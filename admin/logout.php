<?php
require_once dirname(__DIR__) . '/includes/init.php';
if (is_staff()) {
    admin_log('admin.logout');
}
redirect('auth/logout.php');
