<?php
if(!defined('OSTADMININC') || !$thisstaff || !$thisstaff->isAdmin() || !$config) die('Access Denied');
$favicon = $cfg->getBrandFavicon();
$accent = $config['brand_accent'] ?: $cfg->getBrandAccent();
$presets = array(
    '#1f4e79' => __('Navy'), '#0f766e' => __('Teal'), '#4338ca' => __('Indigo'),
    '#7c2d12' => __('Rust'), '#365314' => __('Olive'), '#111827' => __('Graphite'),
);
?>
<h2><?php echo __('Branding and Appearance'); ?></h2>
<form action="settings.php?t=branding" method="post" class="save" enctype="multipart/form-data">
<?php csrf_token(); ?>
<input type="hidden" name="t" value="branding" >
<ul class="clean tabs">
    <li class="active"><a href="#brand-identity"><i class="icon-tint"></i> <?php echo __('Identity'); ?></a></li>
    <li><a href="#brand-layout"><i class="icon-desktop"></i> <?php echo __('Layout'); ?></a></li>
    <li><a href="#brand-css"><i class="icon-code"></i> <?php echo __('Custom CSS'); ?></a></li>
</ul>
<div class="tab_content" id="brand-identity">
<table class="form_table settings_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr><th colspan="2"><em><?php echo __('The accent colour, name and favicon are applied to the agent panel, the client portal and the login pages. Logos are managed under Company → Logos.'); ?></em></th></tr>
    </thead>
    <tbody>
        <tr>
            <td width="220"><?php echo __('Accent colour'); ?>:</td>
            <td>
                <input type="color" name="brand_accent" value="<?php echo Format::htmlchars($accent); ?>" style="vertical-align:middle;width:48px;height:32px;padding:2px;border:1px solid #ccc;border-radius:4px">
                <span class="brand-presets" style="margin-left:8px">
<?php foreach ($presets as $hex => $label) { ?>
                    <button type="button" class="brand-preset" data-color="<?php echo $hex; ?>" title="<?php echo $label; ?>" style="background:<?php echo $hex; ?>"></button>
<?php } ?>
                </span>
                <span class="error"><?php echo $errors['brand_accent']; ?></span>
                <i class="help-tip icon-question-sign" href="#accent"></i>
                <div class="faded field-hint"><?php echo __('Used for the active navigation, primary buttons, links and chart highlights. Text on accent surfaces switches between white and dark automatically.'); ?></div>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Display name'); ?>:</td>
            <td>
                <input type="text" size="40" name="brand_name" value="<?php echo Format::htmlchars($config['brand_name']); ?>" placeholder="<?php echo Format::htmlchars($cfg->getTitle()); ?>">
                <div class="faded field-hint"><?php echo __('Shown next to the logo in the agent panel header. Leave blank to show the help desk name.'); ?></div>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Tagline'); ?>:</td>
            <td>
                <input type="text" size="40" name="brand_tagline" value="<?php echo Format::htmlchars($config['brand_tagline']); ?>" placeholder="<?php echo __('e.g. IT Service Desk'); ?>">
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Favicon'); ?>:</td>
            <td>
<?php if ($favicon) { ?>
                <img src="<?php echo ROOT_PATH; ?>scp/logo.php?favicon&amp;<?php echo strtotime($cfg->lastModified('brand_favicon_id')); ?>" alt="" style="width:24px;height:24px;vertical-align:middle;margin-right:8px">
                <label class="checkbox inline"><input type="checkbox" name="remove-favicon" value="1"> <?php echo __('Remove'); ?></label><br>
<?php } ?>
                <input type="file" name="favicon" accept=".png,.ico,.svg,.jpg,.jpeg,.gif,.webp">
                <span class="error"><?php echo $errors['favicon']; ?></span>
                <div class="faded field-hint"><?php echo __('PNG, ICO or SVG, ideally 32×32 or larger and square. Under 512 KB.'); ?></div>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Footer'); ?>:</td>
            <td>
                <label class="checkbox inline"><input type="checkbox" name="brand_hide_powered" value="1" <?php echo $config['brand_hide_powered'] ? 'checked' : ''; ?>> <?php echo __('Hide the "Powered by osTicket" notice on the client portal and login pages'); ?></label>
            </td>
        </tr>
    </tbody>
</table>
</div>
<div class="hidden tab_content" id="brand-layout">
<table class="form_table settings_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <tbody>
        <tr>
            <td width="220"><?php echo __('Colour scheme'); ?>:</td>
            <td>
<?php foreach (OsticketConfig::$brandThemes as $k => $label) { ?>
                <label class="inline"><input type="radio" name="brand_theme" value="<?php echo $k; ?>" <?php echo ($config['brand_theme'] ?: 'light') == $k ? 'checked' : ''; ?>> <?php echo __($label); ?></label>&nbsp;&nbsp;
<?php } ?>
                <i class="help-tip icon-question-sign" href="#theme"></i>
                <div class="faded field-hint"><?php echo __('Agents can still override the scheme for themselves from their profile menu.'); ?></div>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Density'); ?>:</td>
            <td>
<?php foreach (OsticketConfig::$brandDensities as $k => $label) { ?>
                <label class="inline"><input type="radio" name="brand_density" value="<?php echo $k; ?>" <?php echo ($config['brand_density'] ?: 'comfortable') == $k ? 'checked' : ''; ?>> <?php echo __($label); ?></label>&nbsp;&nbsp;
<?php } ?>
                <div class="faded field-hint"><?php echo __('Compact tightens tables and forms for large screens and busy queues.'); ?></div>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Navigation'); ?>:</td>
            <td>
<?php foreach (OsticketConfig::$brandNavStyles as $k => $label) { ?>
                <label class="inline"><input type="radio" name="brand_nav_style" value="<?php echo $k; ?>" <?php echo ($config['brand_nav_style'] ?: 'top') == $k ? 'checked' : ''; ?>> <?php echo __($label); ?></label>&nbsp;&nbsp;
<?php } ?>
                <div class="faded field-hint"><?php echo __('Top bar keeps the classic horizontal menus; Sidebar moves the main sections to a vertical rail on wide screens.'); ?></div>
            </td>
        </tr>
    </tbody>
</table>
</div>
<div class="hidden tab_content" id="brand-css">
<table class="form_table settings_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr><th colspan="2"><em><?php echo __('Additional CSS appended to every page of the agent panel and client portal. Design tokens you can override: --ost-accent, --ost-bg, --ost-surface, --ost-text, --ost-muted, --ost-border, --ost-radius, --ost-font.'); ?></em></th></tr>
    </thead>
    <tbody>
        <tr>
            <td colspan="2">
                <textarea name="brand_custom_css" rows="14" style="width:100%;box-sizing:border-box;font-family:Menlo,Consolas,monospace;font-size:12px" spellcheck="false" placeholder=":root { --ost-radius: 10px; }
#header .brand-name { letter-spacing: .02em; }"><?php echo Format::htmlchars($config['brand_custom_css']); ?></textarea>
            </td>
        </tr>
    </tbody>
</table>
</div>
<p style="text-align:center;">
    <input class="button" type="submit" name="submit-button" value="<?php echo __('Save Changes'); ?>">
    <input class="button" type="reset" name="reset" value="<?php echo __('Reset Changes'); ?>">
</p>
</form>
<script type="text/javascript">
$(function() {
    $('.brand-preset').on('click', function(e) {
        e.preventDefault();
        $('input[name=brand_accent]').val($(this).data('color')).trigger('change');
    });
    $('input[name=brand_accent]').on('input change', function() {
        document.documentElement.style.setProperty('--ost-accent', this.value);
    });
});
</script>
