    <div class="app-main">
        <header class="topbar">
            <div class="topbar-title"><?php echo isset($page_title) ? e($page_title) : e(APP_NAME); ?><small><?php echo e(APP_NAME); ?></small></div>
            <div class="topbar-search hide-xs">
                <div class="search-wrap">
                    <div class="input-group input-group-sm">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-search"></i></span></div>
                        <input type="text" id="globalSearch" class="form-control" placeholder="Search products, customers, invoices, sales..." autocomplete="off">
                    </div>
                    <div class="search-suggest" id="globalSearchSuggest"></div>
                </div>
            </div>
            <div class="topbar-spacer"></div>
            <div class="dropdown">
                <a href="#" class="user-menu" data-toggle="dropdown">
                    <span class="avatar"><?php echo e(strtoupper(substr($current_user->first_name, 0, 1))); ?></span>
                    <span class="hide-xs"><strong><?php echo e($current_user->fullName()); ?></strong><br><small class="text-muted"><?php echo e($current_user->email); ?></small></span>
                    <i class="fa fa-chevron-down hide-xs" style="font-size:10px;color:var(--text-muted)"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="profile.php"><i class="fa fa-user mr-2"></i>My Profile</a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger" href="logout.php"><i class="fa fa-sign-out mr-2"></i>Logout</a>
                </div>
            </div>
        </header>
        <main class="app-content">
