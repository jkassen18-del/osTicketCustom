<?php
header("Content-Type: text/html; charset=UTF-8");
header("Content-Security-Policy: frame-ancestors ".$cfg->getAllowIframes()."; script-src 'self' 'unsafe-inline' 'unsafe-eval'; object-src 'none'");

$title = ($ost && ($title=$ost->getPageTitle()))
    ? $title : ('osTicket :: '.__('Staff Control Panel'));

$bodyClasses = sprintf('ost-theme-%s ost-density-%s ost-nav-%s',
    $cfg->getBrandTheme(), $cfg->getBrandDensity(), $cfg->getBrandNavStyle());
if (defined('ADMINPAGE'))
    $bodyClasses .= ' ost-admin';

if (!isset($_SERVER['HTTP_X_PJAX'])) { ?>
<!DOCTYPE html>
<html<?php
if (($lang = Internationalization::getCurrentLanguage())
        && ($info = Internationalization::getLanguageInfo($lang))
        && (@$info['direction'] == 'rtl'))
    echo ' dir="rtl" class="rtl"';
if ($lang) {
    echo ' lang="' . Internationalization::rfc1766($lang) . '"';
}

// Dropped IE Support Warning
if (osTicket::is_ie())
    $ost->setWarning(__('osTicket no longer supports Internet Explorer.'));
?>>
<head>
    <meta http-equiv="content-type" content="text/html; charset=UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta http-equiv="cache-control" content="no-cache" />
    <meta http-equiv="pragma" content="no-cache" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="x-pjax-version" content="<?php echo GIT_VERSION; ?>">
    <meta name="theme-color" content="<?php echo Format::htmlchars($cfg->getBrandAccent()); ?>">
    <title><?php echo Format::htmlchars($title); ?></title>
    <script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="<?php echo ROOT_PATH ?>css/thread.css" media="all">
    <link rel="stylesheet" href="<?php echo ROOT_PATH ?>scp/css/scp.css" media="all">
    <link rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/redactor.css" media="screen">
    <link rel="stylesheet" href="<?php echo ROOT_PATH ?>css/typeahead.css" media="screen">
    <link type="text/css" href="<?php echo ROOT_PATH; ?>css/ui-lightness/jquery-ui-1.13.2.custom.min.css"
         rel="stylesheet" media="screen" />
    <link rel="stylesheet" href="<?php echo ROOT_PATH ?>css/jquery-ui-timepicker-addon.css" media="all">
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/font-awesome.min.css">
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH ?>scp/css/dropdown.css">
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/loadingbar.css"/>
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/flags.css">
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/select2.min.css">
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH; ?>css/rtl.css"/>
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH ?>scp/css/translatable.css"/>
    <link type="text/css" rel="stylesheet" href="<?php echo ROOT_PATH ?>scp/css/modern.css?v=<?php echo urlencode(GIT_VERSION); ?>" media="all">
    <style id="brand-css"><?php echo $cfg->getBrandCss(); ?></style>
    <!-- Favicons -->
<?php if ($cfg->getBrandFaviconId()) { ?>
    <link rel="icon" href="<?php echo ROOT_PATH ?>scp/logo.php?favicon&amp;<?php echo strtotime($cfg->lastModified('brand_favicon_id')); ?>" />
<?php } else { ?>
    <link rel="icon" type="image/png" href="<?php echo ROOT_PATH ?>images/oscar-favicon-32x32.png" sizes="32x32" />
    <link rel="icon" type="image/png" href="<?php echo ROOT_PATH ?>images/oscar-favicon-16x16.png" sizes="16x16" />
<?php } ?>
    <script type="text/javascript">
    // Resolve the colour scheme before the first paint
    (function () {
        try {
            var pref = window.localStorage.getItem('ost-theme') || '<?php echo $cfg->getBrandTheme(); ?>';
            if (pref === 'auto')
                pref = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', pref);
        } catch (e) {}
    })();
    </script>
    <?php
    if($ost && ($headers=$ost->getExtraHeaders())) {
        echo "\n\t".implode("\n\t", $headers)."\n";
    }
    ?>
</head>
<body class="<?php echo $bodyClasses; ?>">
<div id="container">
    <?php
    if($ost->getError())
        echo sprintf('<div id="error_bar">%s</div>', $ost->getError());
    elseif($ost->getWarning())
        echo sprintf('<div id="warning_bar">%s</div>', $ost->getWarning());
    elseif($ost->getNotice())
        echo sprintf('<div id="notice_bar">%s</div>', $ost->getNotice());
    ?>
    <div id="header">
        <a href="<?php echo ROOT_PATH ?>scp/index.php" class="no-pjax" id="logo">
            <img src="<?php echo ROOT_PATH ?>scp/logo.php?<?php echo strtotime($cfg->lastModified('staff_logo_id')); ?>" alt="<?php echo Format::htmlchars($cfg->getBrandName() ?: $cfg->getTitle()); ?>"/>
<?php   if ($cfg->getBrandName() || $cfg->getBrandTagline()) { ?>
            <span class="brand-text">
<?php       if ($cfg->getBrandName()) { ?>
                <span class="brand-name"><?php echo Format::htmlchars($cfg->getBrandName()); ?></span>
<?php       }
            if ($cfg->getBrandTagline()) { ?>
                <span class="brand-tagline"><?php echo Format::htmlchars($cfg->getBrandTagline()); ?></span>
<?php       } ?>
            </span>
<?php   } ?>
        </a>
        <span class="app-bar-spacer"></span>
        <div id="header-actions" class="no-pjax">
<?php   if ($thisstaff->isAdmin() && !defined('ADMINPAGE')) { ?>
            <a href="<?php echo ROOT_PATH ?>scp/admin.php" class="no-pjax panel-switch admin" title="<?php echo __('Admin Panel'); ?>"><i class="icon-wrench"></i> <?php echo __('Admin Panel'); ?></a>
<?php   } elseif (defined('ADMINPAGE')) { ?>
            <a href="<?php echo ROOT_PATH ?>scp/index.php" class="no-pjax panel-switch" title="<?php echo __('Agent Panel'); ?>"><i class="icon-inbox"></i> <?php echo __('Agent Panel'); ?></a>
<?php   } ?>
            <div class="user-menu" data-dropdown="#user-menu-dropdown" data-placement="bottom">
<?php
            $avatar = $cfg->isAvatarsEnabled() ? $thisstaff->getAvatar() : null;
            if ($avatar) {
                echo '<span class="avatar">'.$avatar.'</span>';
            } else {
                $initials = mb_strtoupper(mb_substr((string) $thisstaff->getFirstName(), 0, 1)
                    . mb_substr((string) $thisstaff->getLastName(), 0, 1));
                echo '<span class="avatar initials">'.Format::htmlchars($initials ?: '?').'</span>';
            }
?>
                <span class="user-name"><?php echo Format::htmlchars($thisstaff->getFirstName()); ?>
                    <span class="user-role"><?php echo $thisstaff->isAdmin() ? __('Administrator') : __('Agent'); ?></span>
                </span>
                <i class="icon-caret-down"></i>
            </div>
            <div id="user-menu-dropdown" class="action-dropdown anchor-right">
                <ul>
                    <li><a href="<?php echo ROOT_PATH ?>scp/profile.php"><i class="icon-user icon-fixed-width"></i> <?php echo __('My Profile'); ?></a></li>
                    <li><a href="<?php echo ROOT_PATH ?>scp/dashboard.php" class="no-pjax"><i class="icon-dashboard icon-fixed-width"></i> <?php echo __('Dashboard'); ?></a></li>
<?php       if ($thisstaff->isAdmin() && !defined('ADMINPAGE')) { ?>
                    <li><a href="<?php echo ROOT_PATH ?>scp/admin.php" class="no-pjax"><i class="icon-wrench icon-fixed-width"></i> <?php echo __('Admin Panel'); ?></a></li>
<?php       } elseif (defined('ADMINPAGE')) { ?>
                    <li><a href="<?php echo ROOT_PATH ?>scp/index.php" class="no-pjax"><i class="icon-inbox icon-fixed-width"></i> <?php echo __('Agent Panel'); ?></a></li>
<?php       } ?>
                    <li><a href="#" id="theme-toggle" class="no-pjax" data-dark-label="<?php echo __('Dark mode'); ?>" data-light-label="<?php echo __('Light mode'); ?>"><i class="icon-moon icon-fixed-width"></i> <span><?php echo __('Dark mode'); ?></span></a></li>
                    <li class="danger"><a href="<?php echo ROOT_PATH ?>scp/logout.php?auth=<?php echo $ost->getLinkToken(); ?>" class="no-pjax"><i class="icon-signout icon-fixed-width"></i> <?php echo __('Log Out'); ?></a></li>
                </ul>
            </div>
        </div>
    </div>
    <div id="pjax-container" class="<?php if ($_POST) echo 'no-pjax'; ?>">
<?php } else {
    header('X-PJAX-Version: ' . GIT_VERSION);
    if ($pjax = $ost->getExtraPjax()) { ?>
    <script type="text/javascript">
    <?php foreach (array_filter($pjax) as $s) echo $s.";"; ?>
    </script>
    <?php }
    foreach ($ost->getExtraHeaders() as $h) {
        if (strpos($h, '<script ') !== false)
            echo $h;
    } ?>
    <title><?php echo ($ost && ($title=$ost->getPageTitle()))?$title:'osTicket :: '.__('Staff Control Panel'); ?></title><?php
} # endif X_PJAX ?>
    <ul id="nav">
<?php include STAFFINC_DIR . "templates/navigation.tmpl.php"; ?>
    </ul>
    <div id="main">
    <?php include STAFFINC_DIR . "templates/sub-navigation.tmpl.php"; ?>

        <div id="content">
        <?php if(isset($errors['err'])) { ?>
            <div id="msg_error"><?php echo $errors['err']; ?></div>
        <?php }elseif($msg) { ?>
            <div id="msg_notice"><?php echo $msg; ?></div>
        <?php }elseif($warn) { ?>
            <div id="msg_warning"><?php echo $warn; ?></div>
        <?php }
        foreach (Messages::getMessages() as $M) { ?>
            <div class="<?php echo strtolower($M->getLevel()); ?>-banner"><?php
                echo (string) $M; ?></div>
<?php   } ?>
