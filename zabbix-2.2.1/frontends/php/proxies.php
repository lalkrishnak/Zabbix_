<?php
/*
** Zabbix
** Copyright (C) 2001-2013 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


require_once dirname(__FILE__).'/include/config.inc.php';
require_once dirname(__FILE__).'/include/hosts.inc.php';

$page['title'] = _('Configuration of proxies');
$page['file'] = 'proxies.php';
$page['hist_arg'] = array('');

require_once dirname(__FILE__).'/include/page_header.php';

// VAR	TYPE	OPTIONAL	FLAGS	VALIDATION	EXCEPTION
$fields = array(
	'proxyid' =>		array(T_ZBX_INT, O_OPT, P_SYS,	DB_ID,		'isset({form})&&{form}=="update"'),
	'host' =>			array(T_ZBX_STR, O_OPT, null,	NOT_EMPTY,	'isset({save})', _('Proxy name')),
	'status' =>			array(T_ZBX_INT, O_OPT, null,	BETWEEN(HOST_STATUS_PROXY_ACTIVE,HOST_STATUS_PROXY_PASSIVE), 'isset({save})'),
	'interface' =>		array(T_ZBX_STR, O_OPT, null,	null,		'isset({save})&&{status}=='.HOST_STATUS_PROXY_PASSIVE),
	'hosts' =>			array(T_ZBX_INT, O_OPT, P_SYS,	DB_ID,		null),
	// actions
	'go' =>				array(T_ZBX_STR, O_OPT, P_SYS|P_ACT, null,	null),
	'save' =>			array(T_ZBX_STR, O_OPT, P_SYS|P_ACT, null,	null),
	'clone' =>			array(T_ZBX_STR, O_OPT, P_SYS|P_ACT, null,	null),
	'delete' =>			array(T_ZBX_STR, O_OPT, P_SYS|P_ACT, null,	null),
	'cancel' =>			array(T_ZBX_STR, O_OPT, P_SYS,	null,		null),
	'form' =>			array(T_ZBX_STR, O_OPT, P_SYS,	null,		null),
	'form_refresh' =>	array(T_ZBX_STR, O_OPT, null,	null,		null)
);
check_fields($fields);
validate_sort_and_sortorder('host', ZBX_SORT_UP);

/*
 * Permissions
 */
if (isset($_REQUEST['proxyid'])) {
	$dbProxy = API::Proxy()->get(array(
		'proxyids' => get_request('proxyid'),
		'selectHosts' => array('hostid', 'host'),
		'selectInterface' => API_OUTPUT_EXTEND,
		'output' => API_OUTPUT_EXTEND
	));
	if (empty($dbProxy)) {
		access_deny();
	}
}
if (isset($_REQUEST['go'])) {
	if (!isset($_REQUEST['hosts']) || !is_array($_REQUEST['hosts'])) {
		access_deny();
	}
	else {
		$dbProxyChk = API::Proxy()->get(array(
			'proxyids' => $_REQUEST['hosts'],
			'selectHosts' => array('hostid', 'host'),
			'selectInterface' => API_OUTPUT_EXTEND,
			'countOutput' => true
		));
		if ($dbProxyChk != count($_REQUEST['hosts'])) {
			access_deny();
		}
	}
}
$_REQUEST['go'] = get_request('go', 'none');

/*
 * Actions
 */
if (isset($_REQUEST['save'])) {
	$proxy = array(
		'host' => get_request('host'),
		'status' => get_request('status'),
		'interface' => get_request('interface')
	);

	// skip discovered hosts
	$proxy['hosts'] = API::Host()->get(array(
		'hostids' => get_request('hosts', array()),
		'output' => array('hostid'),
		'filter' => array('flags' => ZBX_FLAG_DISCOVERY_NORMAL)
	));

	DBstart();

	if (isset($_REQUEST['proxyid'])) {
		$proxy['proxyid'] = $_REQUEST['proxyid'];
		$proxyIds = API::Proxy()->update($proxy);

		$action = AUDIT_ACTION_UPDATE;
		$msgOk = _('Proxy updated');
		$msgFail = _('Cannot update proxy');
	}
	else {
		$proxyIds = API::Proxy()->create($proxy);

		$action = AUDIT_ACTION_ADD;
		$msgOk = _('Proxy added');
		$msgFail = _('Cannot add proxy');
	}

	$result = DBend($proxyIds);

	show_messages($result, $msgOk, $msgFail);
	clearCookies($result);

	if ($result) {
		add_audit($action, AUDIT_RESOURCE_PROXY, '['.$_REQUEST['host'].'] ['.reset($proxyIds['proxyids']).']');
		unset($_REQUEST['form']);
	}
	unset($_REQUEST['save']);
}
elseif (isset($_REQUEST['delete'])) {
	$result = API::Proxy()->delete(array($_REQUEST['proxyid']));

	if ($result) {
		unset($_REQUEST['form'], $_REQUEST['proxyid']);
		$proxy = reset($dbProxy);
	}

	show_messages($result, _('Proxy deleted'), _('Cannot delete proxy'));
	clearCookies($result);

	unset($_REQUEST['delete']);
}
elseif (isset($_REQUEST['clone']) && isset($_REQUEST['proxyid'])) {
	unset($_REQUEST['proxyid'], $_REQUEST['hosts']);
	$_REQUEST['form'] = 'clone';
}
elseif (str_in_array($_REQUEST['go'], array('activate', 'disable')) && isset($_REQUEST['hosts'])) {
	$goResult = true;

	$status = ($_REQUEST['go'] == 'activate') ? HOST_STATUS_MONITORED : HOST_STATUS_NOT_MONITORED;
	$hosts = get_request('hosts', array());

	DBstart();

	foreach ($hosts as $hostId) {
		$dbHosts = DBselect(
			'SELECT h.hostid,h.status'.
			' FROM hosts h'.
			' WHERE h.proxy_hostid='.zbx_dbstr($hostId).
				andDbNode('h.hostid')
		);
		while ($dbHost = DBfetch($dbHosts)) {
			$oldStatus = $dbHost['status'];
			if ($oldStatus == $status) {
				continue;
			}

			$goResult &= updateHostStatus($dbHost['hostid'], $status);
			if (!$goResult) {
				continue;
			}
		}
	}

	$goResult = DBend($goResult && $hosts);

	show_messages($goResult, _('Host status updated'), null);
	clearCookies($goResult);
}
elseif ($_REQUEST['go'] == 'delete' && isset($_REQUEST['hosts'])) {
	DBstart();

	$goResult = API::Proxy()->delete(get_request('hosts'));
	$goResult = DBend($goResult);

	show_messages($goResult, _('Proxy deleted'), _('Cannot delete proxy'));
	clearCookies($goResult);
}

/*
 * Display
 */
if (isset($_REQUEST['form'])) {
	$data = array(
		'form' => get_request('form', 1),
		'form_refresh' => get_request('form_refresh', 0) + 1,
		'proxyid' => get_request('proxyid', 0),
		'name' => get_request('host', ''),
		'status' => get_request('status', HOST_STATUS_PROXY_ACTIVE),
		'hosts' => get_request('hosts', array()),
		'interface' => get_request('interface', array()),
		'proxy' => array()
	);

	// proxy
	if (!empty($data['proxyid'])) {
		$dbProxy = reset($dbProxy);

		if (!isset($_REQUEST['form_refresh'])) {
			$data['name'] = $dbProxy['host'];
			$data['status'] = $dbProxy['status'];
			$data['interface'] = $dbProxy['interface'];
			$data['hosts'] = zbx_objectValues($dbProxy['hosts'], 'hostid');
		}
	}

	// interface
	if ($data['status'] == HOST_STATUS_PROXY_PASSIVE && empty($data['interface'])) {
		$data['interface'] = array(
			'dns' => 'localhost',
			'ip' => '127.0.0.1',
			'useip' => 1,
			'port' => '10051'
		);
	}

	// fetch available hosts, skip host prototypes
	$data['dbHosts'] = DBfetchArray(DBselect(
		'SELECT h.hostid,h.proxy_hostid,h.name,h.flags'.
		' FROM hosts h'.
		' WHERE h.status IN ('.HOST_STATUS_MONITORED.','.HOST_STATUS_NOT_MONITORED.')'.
			andDbNode('h.hostid').
			' AND h.flags<>'.ZBX_FLAG_DISCOVERY_PROTOTYPE
	));
	order_result($data['dbHosts'], 'name');

	// render view
	$proxyView = new CView('administration.proxy.edit', $data);
	$proxyView->render();
	$proxyView->show();
}
else {
	$data = array(
		'displayNodes' => is_array(get_current_nodeid()),
		'config' => select_config()
	);

	$sortfield = getPageSortField('host');

	$data['proxies'] = API::Proxy()->get(array(
		'editable' => true,
		'selectHosts' => array('hostid', 'host', 'name', 'status'),
		'output' => API_OUTPUT_EXTEND,
		'sortfield' => $sortfield,
		'limit' => $config['search_limit'] + 1
	));
	$data['proxies'] = zbx_toHash($data['proxies'], 'proxyid');

	$proxyIds = array_keys($data['proxies']);

	// sorting & paging
	order_result($data['proxies'], $sortfield, getPageSortOrder());
	$data['paging'] = getPagingLine($data['proxies'], array('proxyid'));

	// nodes
	foreach ($data['proxies'] as &$proxy) {
		$proxy['nodename'] = $data['displayNodes'] ? get_node_name_by_elid($proxy['proxyid'], true) : '';
	}
	unset($proxy);

	// calculate performance
	$dbPerformance = DBselect(
		'SELECT h.proxy_hostid,SUM(1.0/i.delay) AS qps'.
		' FROM items i,hosts h'.
		' WHERE i.status='.ITEM_STATUS_ACTIVE.
			' AND i.hostid=h.hostid'.
			' AND h.status='.HOST_STATUS_MONITORED.
			' AND i.delay<>0'.
			' AND '.dbConditionInt('h.proxy_hostid', $proxyIds).
		' GROUP BY h.proxy_hostid'
	);
	while ($performance = DBfetch($dbPerformance)) {
		if (isset($data['proxies'][$performance['proxy_hostid']])) {
			$data['proxies'][$performance['proxy_hostid']]['perf'] = round($performance['qps'], 2);
		}
	}

	// get items
	$items = API::Item()->get(array(
		'proxyids' => $proxyIds,
		'groupCount' => true,
		'countOutput' => true,
		'webitems' => true,
		'monitored' => true
	));
	foreach ($items as $item) {
		if (isset($data['proxies'][$item['proxy_hostid']])) {
			if (!isset($data['proxies'][$item['proxy_hostid']]['item_count'])) {
				$data['proxies'][$item['proxy_hostid']]['item_count'] = 0;
			}

			$data['proxies'][$item['proxy_hostid']]['item_count'] += $item['rowscount'];
		}
	}

	// render view
	$proxyView = new CView('administration.proxy.list', $data);
	$proxyView->render();
	$proxyView->show();
}

require_once dirname(__FILE__).'/include/page_footer.php';
