import './bootstrap';
import { startAdminRealtime } from './admin-realtime';
import { startSiteRealtime } from './site-realtime';
import { initOrderTrackingMap } from './order-tracking-maps';
import { initAdminUsersPage } from './admin-users';

document.addEventListener('DOMContentLoaded', () => {
    startAdminRealtime();
    startSiteRealtime();
    initOrderTrackingMap();
    if (document.getElementById('rt-restaurants-grid')) {
        import('./admin-restaurants').then((m) => m.initAdminRestaurantsPage());
    }
    if (document.getElementById('admin-users-config')) {
        initAdminUsersPage();
    }
    if (document.querySelector('[data-order-track-page]')) {
        import('./order-tracking-page').then((m) => m.initOrderTrackingPage());
    }
});

