<?php

/*
 * Copyright 2005-2020 Centreon
 * Centreon is developed by : Julien Mathis and Romain Le Merlus under
 * GPL Licence 2.0.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation ; either version 2 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <http://www.gnu.org/licenses>.
 *
 * Linking this program statically or dynamically with other modules is making a
 * combined work based on this program. Thus, the terms and conditions of the GNU
 * General Public License cover the whole combination.
 *
 * As a special exception, the copyright holders of this program give Centreon
 * permission to link this program with independent modules to produce an executable,
 * regardless of the license terms of these independent modules, and to copy and
 * distribute the resulting executable under terms of Centreon choice, provided that
 * Centreon also meet, for each linked independent module, the terms  and conditions
 * of the license of that module. An independent module is a module which is not
 * derived from this program. If you modify this program, you may extend this
 * exception to your version of the program, but you are not obliged to do so. If you
 * do not wish to do so, delete this exception statement from your version.
 *
 * For more information : contact@centreon.com
 *
 */

require_once "../../require.php";
require_once $centreon_path . 'bootstrap.php';
require_once $centreon_path . 'www/class/centreon.class.php';
require_once $centreon_path . 'www/class/centreonSession.class.php';
require_once $centreon_path . 'www/class/centreonWidget.class.php';
require_once $centreon_path . 'www/class/centreonDuration.class.php';
require_once $centreon_path . 'www/class/centreonUtils.class.php';
require_once $centreon_path . 'www/class/centreonACL.class.php';
require_once $centreon_path . 'www/widgets/hostgroup-monitoring/src/class/HostgroupMonitoring.class.php';

CentreonSession::start(1);

if (!isset($_SESSION['centreon']) || !isset($_REQUEST['widgetId']) || !isset($_REQUEST['page'])) {
    exit;
}
$db = $dependencyInjector['configuration_db'];
if (CentreonSession::checkSession(session_id(), $db) == 0) {
    exit;
}

$path = $centreon_path . "www/widgets/hostgroup-monitoring/src/";
$template = new Smarty();
$template = initSmartyTplForPopup($path, $template, "./", $centreon_path);

$centreon = $_SESSION['centreon'];
$widgetId = filter_var($_REQUEST['widgetId'], FILTER_VALIDATE_INT);
$page = filter_var($_REQUEST['page'], FILTER_VALIDATE_INT);
try {
    if ($widgetId === false) {
        throw new InvalidArgumentException('Widget ID must be an integer');
    }
    if ($page === false) {
        throw new InvalidArgumentException('Page must be an integer');
    }
} catch (InvalidArgumentException $e) {
    echo $e->getMessage();
    exit;
}

/**
 * @var $dbb CentreonDB
 */
$dbb = $dependencyInjector['realtime_db'];
$widgetObj = new CentreonWidget($centreon, $db);
$hgMonObj = new HostgroupMonitoring($dbb);
$preferences = $widgetObj->getWidgetPreferences($widgetId);
$aclObj = new CentreonACL($centreon->user->user_id, $centreon->user->admin);

$aColorHost = array(0 => 'host_up', 1 => 'host_down', 2 => 'host_unreachable', 4 => 'host_pending');
$aColorService = array(
    0 => 'service_ok',
    1 => 'service_warning',
    2 => 'service_critical',
    3 => 'service_unknown',
    4 => 'pending'
);


$hostStateColors = array(
    0 => "#88b917",
    1 => "#e00b3d",
    2 => "#82CFD8",
    4 => "#2ad1d4"
);

$serviceStateColors = array(
    0 => "#88b917",
    1 => "#F8C706",
    2 => "#e00b3d",
    3 => "#DCDADA",
    4 => "#2ad1d4"
);


$hostStateLabels = array(
    0 => "Up",
    1 => "Down",
    2 => "Unreachable",
    4 => "Pending"
);

$serviceStateLabels = array(
    0 => "Ok",
    1 => "Warning",
    2 => "Critical",
    3 => "Unknown",
    4 => "Pending"
);

$query = "SELECT SQL_CALC_FOUND_ROWS DISTINCT name, hostgroup_id ";
$query .= "FROM hostgroups ";

if (isset($preferences['hg_name_search']) && $preferences['hg_name_search'] != "") {
    $tab = explode(" ", $preferences['hg_name_search']);
    $op = $tab[0];
    if (isset($tab[1])) {
        $search = $tab[1];
    }
    if ($op && isset($search) && $search != "") {
        $query = CentreonUtils::conditionBuilder(
            $query,
            "name " . CentreonUtils::operandToMysqlFormat($op) . " '" . $dbb->escape($search) . "' "
        );
    }
}

if (!$centreon->user->admin) {
    $query = CentreonUtils::conditionBuilder($query, "name IN (" . $aclObj->getHostGroupsString("NAME") . ")");
}

$orderby = "name ASC";
if (isset($preferences['order_by']) && trim($preferences['order_by']) != "") {
    $orderby = $preferences['order_by'];
}

$query .= "ORDER BY $orderby";
$query .= " LIMIT " . ($page * $preferences['entries']) . "," . $preferences['entries'];
$res = $dbb->query($query);
$nbRows = $dbb->query('SELECT FOUND_ROWS()')->fetchColumn();
$data = array();
$detailMode = false;
if (isset($preferences['enable_detailed_mode']) && $preferences['enable_detailed_mode']) {
    $detailMode = true;
}

$kernel = \App\Kernel::createForWeb();
$resourceController = $kernel->getContainer()->get(
    \Centreon\Application\Controller\MonitoringResourceController::class
);

$buildHostgroupUri = function (array $hostgroup, array $types, array $statuses) use ($resourceController) {
    return $resourceController->buildListingUri(
        [
            'filter' => json_encode(
                [
                    'criterias' => [
                        [
                            'name' => 'host_groups',
                            'value' => $hostgroup,
                        ],
                        [
                            'name' => 'resource_types',
                            'value' => $types,
                        ],
                        [
                            'name' => 'statuses',
                            'value' => $statuses,
                        ]
                    ],
                ]
            )
        ]
    );
};

$buildParameter = function (string $id, string $name) {
    return [
        'id' => $id,
        'name' => $name,
    ];
};

$hostType = $buildParameter('host', 'Host');
$serviceType = $buildParameter('service', 'Service');
$okStatus = $buildParameter('OK', 'Ok');
$warningStatus = $buildParameter('WARNING', 'Warning');
$criticalStatus = $buildParameter('CRITICAL', 'Critical');
$unknownStatus = $buildParameter('UNKNOWN', 'Unknown');
$pendingStatus = $buildParameter('PENDING', 'Pending');
$upStatus = $buildParameter('UP', 'Up');
$downStatus = $buildParameter('DOWN', 'Down');
$unreachableStatus = $buildParameter('UNREACHABLE', 'Unreachable');

while ($row = $res->fetch()) {
    $hostgroup = [
        'id' => (int)$row['hostgroup_id'],
        'name' => $row['name'],
    ];

    $data[$row['name']] = [
        'name' => $row['name'],
        'hg_id' => $row['hostgroup_id'],
        'hg_uri' => $buildHostgroupUri([$hostgroup], [], []),
        'hg_service_uri' => $buildHostgroupUri([$hostgroup], [$serviceType], []),
        'hg_service_ok_uri' => $buildHostgroupUri([$hostgroup], [$serviceType], [$okStatus]),
        'hg_service_warning_uri' => $buildHostgroupUri([$hostgroup], [$serviceType], [$warningStatus]),
        'hg_service_critical_uri' => $buildHostgroupUri([$hostgroup], [$serviceType], [$criticalStatus]),
        'hg_service_unknown_uri' => $buildHostgroupUri([$hostgroup], [$serviceType], [$unknownStatus]),
        'hg_service_pending_uri' => $buildHostgroupUri([$hostgroup], [$serviceType], [$pendingStatus]),
        'hg_host_uri' => $buildHostgroupUri([$hostgroup], [$hostType], []),
        'hg_host_up_uri' => $buildHostgroupUri([$hostgroup], [$hostType], [$upStatus]),
        'hg_host_down_uri' => $buildHostgroupUri([$hostgroup], [$hostType], [$downStatus]),
        'hg_host_unreachable_uri' => $buildHostgroupUri([$hostgroup], [$hostType], [$unreachableStatus]),
        'hg_host_pending_uri' => $buildHostgroupUri([$hostgroup], [$hostType], [$pendingStatus]),
        'host_state' => [],
        'service_state' => [],
    ];
}
$hgMonObj->getHostStates($data, $centreon->user->admin, $aclObj, $preferences, $detailMode);
$hgMonObj->getServiceStates($data, $centreon->user->admin, $aclObj, $preferences, $detailMode);

if ($detailMode === true) {
    foreach ($data as $hostgroupName => &$properties) {
        foreach ($properties['host_state'] as $hostName => &$hostProperties) {
            $hostProperties['details_uri'] = $resourceController->buildHostDetailsUri($hostProperties['host_id']);
        }
        foreach ($properties['service_state'] as $hostId => &$services) {
            foreach ($services as &$serviceProperties) {
                $serviceProperties['details_uri'] = $resourceController->buildServiceDetailsUri(
                    $hostId,
                    $serviceProperties['service_id']
                );
            }
        }
    }
}

$autoRefresh = filter_var($preferences['refresh_interval'], FILTER_VALIDATE_INT);
if ($autoRefresh === false || $autoRefresh < 5) {
    $autoRefresh = 30;
}

$template->assign('widgetId', $widgetId);
$template->assign('autoRefresh', $autoRefresh);
$template->assign('preferences', $preferences);
$template->assign('nbRows', $nbRows);
$template->assign('page', $page);
$template->assign('orderby', $orderby);
$template->assign('data', $data);
$template->assign('dataJS', count($data));
$template->assign('aColorHost', $aColorHost);
$template->assign('aColorService', $aColorService);
$template->assign('centreon_web_path', trim($centreon->optGen['oreon_web_path'], "/"));
$template->assign('preferences', $preferences);
$template->assign('hostStateLabels', $hostStateLabels);
$template->assign('hostStateColors', $hostStateColors);
$template->assign('serviceStateLabels', $serviceStateLabels);
$template->assign('serviceStateColors', $serviceStateColors);
$template->assign('data', $data);

$template->display('table.ihtml');
