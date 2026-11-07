<?php
/** Sign out (GET or POST). */
require __DIR__ . '/inc/admin.php';

if ($u = current_user()) log_activity('auth.logout', 'Signed out');
logout_user();
flash('You have been signed out.', 'info');
redirect(admin_url('login.php'));
