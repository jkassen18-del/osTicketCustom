<?php
/*********************************************************************
    access.php

    Admin Panel → Agents → Access: one view of who can work in which
    department with which role, plus quick grant / revoke.

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/
require('admin.inc.php');
require_once(INCLUDE_DIR.'class.staff.php');
require_once(INCLUDE_DIR.'class.dept.php');
require_once(INCLUDE_DIR.'class.role.php');

if ($_POST) {
    $staff = Staff::lookup((int) $_POST['staff_id']);
    $dept = Dept::lookup((int) $_POST['dept_id']);
    switch (strtolower($_POST['do'])) {
    case 'grant':
        $role = Role::lookup((int) $_POST['role_id']);
        if (!$staff || !$dept || !$role)
            $errors['err'] = __('Select an agent, a department and a role');
        elseif ((int) $staff->getDeptId() === (int) $dept->getId())
            $errors['err'] = sprintf(__('%s is already a primary member of %s'), $staff->getName(), $dept->getName());
        else {
            $access = array();
            foreach ($staff->dept_access as $da)
                if ((int) $da->dept_id !== (int) $dept->getId())
                    $access[] = array($da->dept_id, $da->role_id, $da->isAlertsEnabled());
            $access[] = array($dept->getId(), $role->getId(), isset($_POST['alerts']) ? 1 : 0);
            $e = array();
            $staff->updateAccess($access, $e);
            if ($e)
                $errors['err'] = Format::array_implode(': ', '; ', $e['dept_access'] ?: $e);
            else
                $msg = sprintf(__('%s now has %s access to %s'), $staff->getName(), $role->getName(), $dept->getName());
        }
        break;
    case 'revoke':
        if (!$staff || !$dept)
            $errors['err'] = __('Unknown agent or department');
        elseif ((int) $staff->getDeptId() === (int) $dept->getId())
            $errors['err'] = __('Primary department membership is changed on the agent profile');
        else {
            $access = array();
            foreach ($staff->dept_access as $da)
                if ((int) $da->dept_id !== (int) $dept->getId())
                    $access[] = array($da->dept_id, $da->role_id, $da->isAlertsEnabled());
            $e = array();
            $staff->updateAccess($access, $e);
            $msg = sprintf(__('Access of %s to %s revoked'), $staff->getName(), $dept->getName());
        }
        break;
    }
}

$nav->setTabActive('staff', 'access.php');
$ost->addExtraHeader('<meta name="tip-namespace" content="staff.access" />',
    "$('#content').data('tipNamespace', 'staff.access');");
require(STAFFINC_DIR.'header.inc.php');
require(STAFFINC_DIR.'access.inc.php');
include(STAFFINC_DIR.'footer.inc.php');
?>
