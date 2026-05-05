<?php

namespace App\Services;

use App\Models\CheckoutOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RestaurantAdminAiReplyService
{
    public function __construct(
        private OrderMapLocationService $orderMapLocation,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function reply(CheckoutOrder $order, array $messages): string
    {
        $key = trim((string) config('services.openai.api_key', ''));
        if ($key === '') {
            return $this->fallbackReply($order, $messages);
        }

        $model = (string) config('services.openai.model', 'gpt-4o-mini');
        $map = $this->orderMapLocation->resolveForCheckoutOrder($order);
        $system = $this->systemPrompt($order, $map);

        $payload = [
            ['role' => 'system', 'content' => $system],
            ...$this->trimMessages($messages),
        ];

        try {
            $response = Http::timeout(45)
                ->withToken($key)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'messages' => $payload,
                    'max_tokens' => 800,
                    'temperature' => 0.78,
                    'frequency_penalty' => 0.45,
                    'presence_penalty' => 0.25,
                ]);

            if (! $response->successful()) {
                Log::warning('OpenAI restaurant chat HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return $this->fallbackReply($order, $messages);
            }

            $text = $response->json('choices.0.message.content');
            if (! is_string($text) || trim($text) === '') {
                return $this->fallbackReply($order, $messages);
            }

            return trim($text);
        } catch (\Throwable $e) {
            Log::warning('OpenAI restaurant chat exception', ['e' => $e->getMessage()]);

            return $this->fallbackReply($order, $messages);
        }
    }

    /**
     * @param  array{
     *   restaurantLat: ?float,
     *   restaurantLng: ?float,
     *   mapQuery: string,
     *   mapQueryAlternates: list<string>
     * }  $map
     */
    private function systemPrompt(CheckoutOrder $order, array $map): string
    {
        $status = (string) ($order->fulfillment_status ?? '');
        $method = $order->fulfillment_method === 'delivery' ? 'delivery' : 'pickup';
        $mapQuery = trim((string) ($map['mapQuery'] ?? ''));
        $lat = $map['restaurantLat'];
        $lng = $map['restaurantLng'];
        $coords = ($lat !== null && $lng !== null)
            ? sprintf('%.6f, %.6f', $lat, $lng)
            : 'tidak tersedia di data kami';
        $mapsUrl = $this->googleMapsSearchUrl($mapQuery !== '' ? $mapQuery : $order->restaurant_name.', Indonesia');

        return <<<PROMPT
Kamu adalah asisten AI yang mewakili pihak restoran / admin toko {$order->restaurant_name} di SurpriseBite. WAJIB bahasa Indonesia.

Data pesanan (jangan mengarang nomor telepon pribadi, alamat pengiriman lengkap pelanggan, atau data sensitif):
- ID: {$order->public_order_id}
- Restoran: {$order->restaurant_name}
- Mystery box: {$order->box_title}
- Status: {$status}
- Metode: {$method}
- Jendela waktu: {$order->pickup_time}
- Query pencarian Maps (pakai persis jika user minta lokasi): "{$mapQuery}"
- Koordinat toko (referensi): {$coords}

Cara menjawab (penting):
- Baca riwayat chat: tanggapi LANGSUNG pertanyaan terbaru; sebut topik user secara natural (boleh singkat).
- Hindari template yang sama tiap pesan; jangan buka dengan kalimat identik berulang.
- Panjang fleksibel: singkat bila cukup; lebih uraian bila user minta penjelasan, prosedur, atau beberapa hal sekaligus.
- FAQ restoran (jam buka umum, parkir, cara ambil pesanan, alergen secara umum, isi box tidak spesifik): jawab masuk akal tanpa klaim hard bila tidak ada di data; tegaskan perkiraan atau "biasanya" bila perlu.
- Lokasi/alamat/dimana/maps: beri ringkasan + tautan persis ini (satu baris URL utuh): {$mapsUrl}
- Status pesanan: gunakan nilai "{$status}" dan jelaskan dengan bahasa pelanggan bila ditanya.
- Di luar pengetahuan atau butuh keputusan manusia: akui singkat, sarankan CS platform atau halaman lacak—tanpa mengulang paragraf yang sama.
- Sebut kamu asisten otomatis bila perlu; tidak wajib di setiap balasan.
PROMPT;
    }

    private function googleMapsSearchUrl(string $query): string
    {
        $q = trim($query);

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($q !== '' ? $q : 'Indonesia');
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    private function trimMessages(array $messages): array
    {
        $out = [];
        foreach (array_slice($messages, -20) as $m) {
            $role = $m['role'] ?? '';
            $content = isset($m['content']) ? (string) $m['content'] : '';
            if (! in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
        }

        return $out;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function fallbackReply(CheckoutOrder $order, array $messages): string
    {
        $last = '';
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? '') === 'user' && isset($m['content'])) {
                $last = mb_strtolower((string) $m['content']);
                break;
            }
        }

        $map = $this->orderMapLocation->resolveForCheckoutOrder($order);
        $mapQuery = trim((string) ($map['mapQuery'] ?? ''));
        $q = $mapQuery !== '' ? $mapQuery : $order->restaurant_name.', Indonesia';
        $mapsUrl = $this->googleMapsSearchUrl($q);

        $status = (string) ($order->fulfillment_status ?? '');
        $statusHint = match ($status) {
            'completed' => 'Status pesanan sudah selesai.',
            'ready' => 'Pesanan sudah siap diambil atau menunggu kurir (tergantung metode Anda).',
            'preparing' => 'Tim kami sedang menyiapkan pesanan Anda.',
            'received', 'pending_confirmation' => 'Pesanan sudah kami terima dan akan segera diproses.',
            default => 'Silakan pantau pembaruan di halaman lacak pesanan.',
        };

        if (
            str_contains($last, 'halo') || str_contains($last, 'hai') || str_contains($last, 'selamat')
            || $last === 'hai' || $last === 'halo'
        ) {
            return 'Halo! Ada yang bisa kami bantu untuk '.$order->restaurant_name.'? Bisa tanya soal lokasi, jam layanan, status pesanan '.$order->public_order_id.', atau cara pickup/delivery.';
        }
        if (
            str_contains($last, 'alamat')
            || str_contains($last, 'lokasi')
            || str_contains($last, 'dimana')
            || str_contains($last, 'maps')
            || str_contains($last, 'google')
            || str_contains($last, 'letak')
        ) {
            return "Lokasi pencarian untuk {$order->restaurant_name} di Google Maps:\n{$mapsUrl}\n\n{$statusHint} Detail alamat pada peta mengikuti hasil Maps.";
        }
        if (str_contains($last, 'jam') || str_contains($last, 'buka') || str_contains($last, 'operasional')) {
            return 'Jam operasional pasti bisa berbeda per cabang; yang tercatat untuk jendela pesanan Anda: '.($order->pickup_time ?: 'lihat di detail order').'. Untuk konfirmasi jam buka spesifik, cross-check di profil restoran atau datang sesuai jendela tersebut.';
        }
        if (str_contains($last, 'apakah') && (str_contains($last, 'halal') || str_contains($last, 'alerg') || str_contains($last, 'pedas'))) {
            return 'Untuk jaminan halal/alergen/tingkat pedas, kebijakannya di masing-masing restoran. Yang pasti pesanan Anda adalah '.$order->box_title.' dari '.$order->restaurant_name.'. Untuk kebutuhan medis ketat, disarankan hubungi restoran langsung atau CS platform.';
        }
        if (str_contains($last, 'apa isi') || str_contains($last, 'mystery') || str_contains($last, 'isi box')) {
            return 'Isi konkret mystery box memang surprise—kami tidak sebut item per item di chat. Anda memesan: '.$order->box_title.'. '.$statusHint;
        }
        if (str_contains($last, 'status') || str_contains($last, 'sudah') && str_contains($last, 'siap')) {
            return $statusHint.' Pesanan '.$order->public_order_id.', metode '.$method.'. Per detail langkah, gunakan juga halaman lacak.';
        }
        if (str_contains($last, 'parkir') || str_contains($last, 'ambil')) {
            return 'Untuk pickup, biasanya ambil di titik restoran tertera di Maps/toko. '.$statusHint.' Tautan pencarian lokasi: '.$mapsUrl;
        }

        if (str_contains($last, 'terima kasih') || str_contains($last, 'thanks') || str_contains($last, 'makasih')) {
            return 'Sama-sama, senang membantu! Kalau ada pertanyaan lain tentang restoran atau pesanan, silakan.';
        }

        $lastUserRaw = '';
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? '') === 'user' && isset($m['content'])) {
                $lastUserRaw = trim((string) $m['content']);
                break;
            }
        }
        $snippet = $lastUserRaw !== '' ? mb_substr($lastUserRaw, 0, 120) : 'pertanyaan Anda';

        return 'Mengenai «'.$snippet.'»: '.$statusHint.' Kami dari sisi '.$order->restaurant_name.' bisa jelaskan lokasi (minta "alamat toko"), jadwal jendela di atas, atau status pesanan—perjelas saja jika kurang pas.';
    }
}
