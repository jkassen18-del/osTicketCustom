<?php
/*********************************************************************
    dashboard.php

    Agent dashboard: key numbers, activity, workload, SLA compliance,
    personal work list, integrations health and the classic statistics
    table with CSV export.

    osTicketCustom (based on osTicket)
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/
require('staff.inc.php');

require_once INCLUDE_DIR . 'class.report.php';
require_once INCLUDE_DIR . 'class.dashboard.php';

$dashboard = new DashboardReport($thisstaff, $_REQUEST['start'] ?? null, $_REQUEST['period'] ?? null);

if ($_POST && isset($_POST['prefs'])) {
    if ($dashboard->savePrefs($_POST))
        $msg = __('Dashboard preferences saved');
}

if ($_POST['export']) {
    $report = new OverviewReport($_POST['start'], $_POST['period']);
    switch (true) {
    case ($data = $report->getTabularData($_POST['export'])):
        $ts = date('Ymd');
        $group = Format::slugify($_POST['export']);
        $delimiter = ',';
        if (class_exists('NumberFormatter')) {
            $nf = NumberFormatter::create(Internationalization::getCurrentLocale(),
                NumberFormatter::DECIMAL);
            $s = $nf->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
            if ($s == ',')
                $delimiter = ';';
        }

        Http::download("stats-$group-$ts.csv", 'text/csv');
        $output = fopen('php://output', 'w');
        fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, $data['columns'], $delimiter, "\"", "");
        foreach ($data['data'] as $row)
            fputcsv($output, $row, $delimiter, "\"", "");
        exit;
    }
}

$nav->setTabActive('dashboard');
$ost->addExtraHeader('<meta name="tip-namespace" content="dashboard.dashboard" />',
    "$('#content').data('tipNamespace', 'dashboard.dashboard');");

require(STAFFINC_DIR.'header.inc.php');
require_once(STAFFINC_DIR.'dashboard.inc.php');
include(STAFFINC_DIR.'footer.inc.php');
?>
