<?php

namespace App\Http\Controllers;

use App\Models\AdminRestaurant;
use App\Models\CustomerPartnerReport;
use App\Models\Restaurant;
use App\Services\CatalogRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PartnerReportController extends Controller
{
    public function store(Request $request, string $slug): RedirectResponse
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'customer') {
            abort(403);
        }

        $catalog = app(CatalogRepository::class)->getCatalog();
        $box = null;
        foreach ($catalog['boxes'] as $b) {
            if ($b['slug'] === $slug) {
                $box = $b;
                break;
            }
        }
        if (! $box) {
            abort(404);
        }

        $validated = $request->validateWithBag('reportPartner', [
            'partner_key' => ['required', 'string', 'max:191'],
            'restaurant_display_name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $expectedKey = (string) ($box['restaurant_id'] ?? '');
        if ($expectedKey === '' || $validated['partner_key'] !== $expectedKey) {
            return back()
                ->withErrors(['partner_key' => 'Data toko tidak cocok dengan halaman ini.'], 'reportPartner')
                ->withInput();
        }

        $partnerKey = $validated['partner_key'];
        $mitraId = null;
        $adminId = null;

        if (preg_match('/^mitra-(\d+)$/', $partnerKey, $m)) {
            $mitraId = (int) $m[1];
            if (! Restaurant::query()->whereKey($mitraId)->exists()) {
                return back()
                    ->withErrors(['partner_key' => 'Toko mitra tidak ditemukan.'], 'reportPartner')
                    ->withInput();
            }
        } else {
            $adminId = AdminRestaurant::query()->where('slug', $partnerKey)->value('id');
        }

        $inCatalog = false;
        foreach ($catalog['restaurants'] as $r) {
            if ((string) ($r['id'] ?? '') === $partnerKey) {
                $inCatalog = true;
                break;
            }
        }

        if (! $inCatalog) {
            return back()
                ->withErrors(['partner_key' => 'Toko tidak valid di katalog.'], 'reportPartner')
                ->withInput();
        }

        CustomerPartnerReport::query()->create([
            'user_id' => $user->id,
            'partner_key' => $partnerKey,
            'mitra_restaurant_id' => $mitraId,
            'admin_restaurant_id' => $adminId,
            'restaurant_display_name' => $validated['restaurant_display_name'],
            'box_slug' => $slug,
            'category' => $validated['category'] ?: null,
            'message' => $validated['message'],
            'status' => 'pending',
        ]);

        return back()->with('partner_report_status', 'Laporan Anda telah dikirim. Tim kami akan meninjaunya.');
    }
}
