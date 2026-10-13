<?php
/*********************************************************************
    reports.php

    Report builder: metric × period × split with filters, charts, tables,
    CSV export and saved presets.

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/
require('staff.inc.php');
require_once INCLUDE_DIR . 'class.dashboard.php';

$presets = ReportBuilder::getPresets($thisstaff);
$params = $_GET;
if (!empty($_GET['preset']) && isset($presets[$_GET['preset']]))
    $params = $presets[$_GET['preset']];
elseif (!empty($_GET['preset']) && ($defaults = ReportBuilder::defaultPresets()) && isset($defaults[$_GET['preset']]))
    $params = $defaults[$_GET['preset']];

if ($_POST) {
    switch ($_POST['do'] ?? '') {
    case 'save-preset':
        if (ReportBuilder::savePreset($thisstaff, $_POST['preset_name'], $_POST))
            $msg = __('Report saved');
        else
            $errors['err'] = __('Give the report a name');
        $params = $_POST;
        break;
    case 'delete-preset':
        ReportBuilder::deletePreset($thisstaff, $_POST['preset_name']);
        $msg = __('Saved report removed');
        $params = $_POST;
        break;
    case 'export':
        $builder = new ReportBuilder($thisstaff, $_POST);
        $result = $builder->run();
        $table = ReportBuilder::toTable($result);
        Http::download('report-' . Format::slugify($result['title']) . '-' . date('Ymd') . '.csv', 'text/csv');
        $out = fopen('php://output', 'w');
        fputs($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, $table['columns'], ',', '"', '');
        foreach ($table['rows'] as $row)
            fputcsv($out, $row, ',', '"', '');
        fputcsv($out, $table['totals'], ',', '"', '');
        exit;
    }
    $presets = ReportBuilder::getPresets($thisstaff);
}

$builder = new ReportBuilder($thisstaff, $params);
$result = $builder->run();
$table = ReportBuilder::toTable($result);

$nav->setTabActive('dashboard', 'reports.php');
$ost->addExtraHeader('<meta name="tip-namespace" content="dashboard.reports" />',
    "$('#content').data('tipNamespace', 'dashboard.reports');");
$ost->setPageTitle(__('osTicket :: Reports'));
require(STAFFINC_DIR.'header.inc.php');
require(STAFFINC_DIR.'reports.inc.php');
include(STAFFINC_DIR.'footer.inc.php');
?>
