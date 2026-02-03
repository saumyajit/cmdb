<?php

namespace Modules\ZabbixCmdb\Actions;

use CController;
use CControllerResponseData;
use API;

require_once dirname(__DIR__) . '/lib/LanguageManager.php';
require_once dirname(__DIR__) . '/lib/ItemFinder.php';
use Modules\ZabbixCmdb\Lib\LanguageManager;
use Modules\ZabbixCmdb\Lib\ItemFinder;

class CmdbExport extends CController {

    public function __construct() {
        parent::__construct();
        
        // Disable CSRF validation for export
        if (method_exists($this, 'disableCsrfValidation')) {
            $this->disableCsrfValidation();
        } elseif (method_exists($this, 'disableSIDvalidation')) {
            $this->disableSIDvalidation();
        }
    }

    protected function checkInput(): bool {
        $fields = [
            'search' => 'string',
            'groupid' => 'int32',
            'interface_type' => 'int32',
            'format' => 'in csv'
        ];

        $ret = $this->validateInput($fields);
        if (!$ret) {
            $this->setResponse(new CControllerResponseData(['error' => 'Invalid input parameters.']));
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $search = $this->getInput('search', '');
        $groupid = $this->getInput('groupid', 0);
        $interface_type = $this->getInput('interface_type', 0);

        // Get host data (same logic as Cmdb.php)
        $hosts = $this->getHostData($search, $groupid, $interface_type);

        // Generate CSV
        $this->exportCSV($hosts);
    }

    private function getHostData($search, $groupid, $interface_type) {
        // Build host query parameters
        if (!empty($search)) {
            $allFoundHosts = [];
            
            // Search by hostname
            try {
                $nameSearchParams = [
                    'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'maintenance_type', 'maintenanceid'],
                    'selectHostGroups' => ['groupid', 'name'],
                    'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'type', 'main', 'available', 'error'],
                    'selectInventory' => ['contact', 'type_full'],
                    'search' => [
                        'host' => '*' . $search . '*',
                        'name' => '*' . $search . '*'
                    ],
                    'searchWildcardsEnabled' => true,
                    'searchByAny' => true,
                    'sortfield' => 'host',
                    'sortorder' => 'ASC',
                    'limit' => 10000
                ];

                if ($groupid > 0) {
                    $nameSearchParams['groupids'] = [$groupid];
                }

                $nameHosts = API::Host()->get($nameSearchParams);
                foreach ($nameHosts as $host) {
                    $allFoundHosts[$host['hostid']] = $host;
                }
            } catch (\Exception $e) {
                error_log("Name search failed: " . $e->getMessage());
            }

            // Search by IP
            if (preg_match('/\d/', $search)) {
                try {
                    $interfaces = API::HostInterface()->get([
                        'output' => ['hostid', 'ip', 'dns'],
                        'search' => [
                            'ip' => '*' . $search . '*',
                            'dns' => '*' . $search . '*'
                        ],
                        'searchWildcardsEnabled' => true,
                        'searchByAny' => true
                    ]);

                    if (!empty($interfaces)) {
                        $hostIds = array_unique(array_column($interfaces, 'hostid'));
                        $ipSearchParams = [
                            'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'maintenance_type', 'maintenanceid'],
                            'selectHostGroups' => ['groupid', 'name'],
                            'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'type', 'main', 'available', 'error'],
                            'selectInventory' => ['contact', 'type_full'],
                            'hostids' => $hostIds,
                            'sortfield' => 'host',
                            'sortorder' => 'ASC'
                        ];

                        if ($groupid > 0) {
                            $ipSearchParams['groupids'] = [$groupid];
                        }

                        $ipHosts = API::Host()->get($ipSearchParams);
                        foreach ($ipHosts as $host) {
                            $allFoundHosts[$host['hostid']] = $host;
                        }
                    }
                } catch (\Exception $e) {
                    error_log("IP search failed: " . $e->getMessage());
                }
            }

            $hosts = array_values($allFoundHosts);
        } else {
            $hostParams = [
                'output' => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'maintenance_type', 'maintenanceid'],
                'selectHostGroups' => ['groupid', 'name'],
                'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'type', 'main', 'available', 'error'],
                'selectInventory' => ['contact', 'type_full'],
                'sortfield' => 'host',
                'sortorder' => 'ASC',
                'limit' => 10000
            ];

            if ($groupid > 0) {
                $hostParams['groupids'] = [$groupid];
            }

            try {
                $hosts = API::Host()->get($hostParams);
            } catch (\Exception $e) {
                error_log("Host fetch failed: " . $e->getMessage());
                $hosts = [];
            }
        }

        // Filter by interface type
        if ($interface_type > 0) {
            $filteredHosts = [];
            foreach ($hosts as $host) {
                if (!empty($host['interfaces'])) {
                    foreach ($host['interfaces'] as $interface) {
                        if ($interface['type'] == $interface_type) {
                            $filteredHosts[] = $host;
                            break;
                        }
                    }
                }
            }
            $hosts = $filteredHosts;
        }

        // Filter out hosts with URL/PUBLIC URL in their names
        $filteredHosts = [];
        foreach ($hosts as $host) {
            $hostName = strtoupper($host['name']);
            if (strpos($hostName, 'URL') === false && strpos($hostName, 'PUBLIC URL') === false) {
                $filteredHosts[] = $host;
            }
        }

        // Process host data
        $hostData = [];
        foreach ($filteredHosts as $host) {
            $hostInfo = [
                'hostid' => $host['hostid'],
                'host' => $host['host'],
                'name' => $host['name'],
                'status' => $host['status'],
                'maintenance_status' => isset($host['maintenance_status']) ? $host['maintenance_status'] : 0,
                'groups' => isset($host['groups']) ? $host['groups'] : (isset($host['hostgroups']) ? $host['hostgroups'] : []),
                'interfaces' => isset($host['interfaces']) ? $host['interfaces'] : [],
                'cpu_total' => '-',
                'cpu_usage' => '-',
                'memory_total' => '-',
                'memory_usage' => '-',
                'customer' => '-',
                'product' => '-',
                'disk_usage' => []
            ];

            // Extract inventory data
            if (isset($host['inventory']) && is_array($host['inventory'])) {
                if (isset($host['inventory']['contact']) && !empty($host['inventory']['contact'])) {
                    $hostInfo['customer'] = $host['inventory']['contact'];
                }
                if (isset($host['inventory']['type_full']) && !empty($host['inventory']['type_full'])) {
                    $hostInfo['product'] = $host['inventory']['type_full'];
                }
            }

            // Get availability status
            $availability = ItemFinder::getHostAvailabilityStatus($host['hostid'], $host['interfaces']);
            $hostInfo['availability'] = $availability;

            // Get main IP
            $mainIp = '';
            foreach ($host['interfaces'] as $interface) {
                if ($interface['main'] == 1) {
                    $mainIp = !empty($interface['ip']) ? $interface['ip'] : $interface['dns'];
                    break;
                }
            }
            $hostInfo['ip'] = $mainIp;

            // Get CPU count
            $cpuResult = ItemFinder::findCpuCount($host['hostid']);
            if ($cpuResult && $cpuResult['value'] !== null) {
                $hostInfo['cpu_total'] = $cpuResult['value'];
            }

            // Get CPU usage
            $cpuUsageResult = ItemFinder::findCpuUsage($host['hostid']);
            if ($cpuUsageResult && $cpuUsageResult['value'] !== null) {
                $hostInfo['cpu_usage'] = round(floatval($cpuUsageResult['value']), 2) . '%';
            }

            // Get memory total
            $memoryResult = ItemFinder::findMemoryTotal($host['hostid']);
            if ($memoryResult && $memoryResult['value'] !== null) {
                $hostInfo['memory_total'] = ItemFinder::formatMemorySize($memoryResult['value']);
            }

            // Get memory usage
            $memoryUsageResult = ItemFinder::findMemoryUsage($host['hostid']);
            if ($memoryUsageResult && $memoryUsageResult['value'] !== null) {
                $hostInfo['memory_usage'] = round(floatval($memoryUsageResult['value']), 2) . '%';
            }

            // Get disk usage
            $diskUsageResult = ItemFinder::findDiskUsage($host['hostid']);
            if ($diskUsageResult !== null) {
                $hostInfo['disk_usage'] = $diskUsageResult;
            }

            // Get OS
            $osResult = ItemFinder::findOperatingSystem($host['hostid']);
            if ($osResult && $osResult['value'] !== null) {
                $hostInfo['operating_system'] = $osResult['value'];
            } else {
                $hostInfo['operating_system'] = '-';
            }

            // Get OS Architecture
            $archResult = ItemFinder::findOsArchitecture($host['hostid']);
            if ($archResult && $archResult['value'] !== null) {
                $hostInfo['os_architecture'] = $archResult['value'];
            } else {
                $hostInfo['os_architecture'] = '-';
            }

            // Get host groups
            $groupNames = [];
            if (isset($host['groups']) && is_array($host['groups'])) {
                $groupNames = array_column($host['groups'], 'name');
            }
            $hostInfo['host_groups'] = implode(', ', $groupNames);

            $hostData[] = $hostInfo;
        }

        return $hostData;
    }

    private function exportCSV($hosts) {
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cmdb_export_' . date('Y-m-d_H-i-s') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add BOM for UTF-8 (helps Excel recognize UTF-8)
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // CSV Headers
        $headers = [
            'Host Name',
            'IP Address',
            'Customer',
            'Product',
            'Architecture',
            'CPU Total',
            'CPU Usage',
            'Memory Total',
            'Memory Usage',
            'Disk Usage',
            'Operating System',
            'Status',
            'Host Groups'
        ];
        fputcsv($output, $headers);

        // CSV Data
        foreach ($hosts as $host) {
            // Format disk usage for CSV
            $diskUsageText = '';
            if (!empty($host['disk_usage'])) {
                $diskParts = [];
                foreach ($host['disk_usage'] as $disk) {
                    $totalSize = isset($disk['total_size']) && $disk['total_size'] > 0 
                        ? ItemFinder::formatMemorySize($disk['total_size']) 
                        : '';
                    $diskParts[] = $disk['mount'] . ': ' . ($totalSize ? $totalSize . ' ' : '') . '(' . $disk['percentage'] . '%)';
                }
                $diskUsageText = implode('; ', $diskParts);
            } else {
                $diskUsageText = '-';
            }

            // Determine status
            $status = 'Unknown';
            if ($host['status'] == 1) {
                $status = 'Disabled';
            } elseif (isset($host['maintenance_status']) && $host['maintenance_status'] == 1) {
                $status = 'Maintenance';
            } else {
                $availability = isset($host['availability']) ? $host['availability'] : ['status' => 'unknown'];
                $status = ucfirst($availability['status']);
            }

            $row = [
                $host['name'],
                $host['ip'],
                $host['customer'],
                $host['product'],
                $host['os_architecture'],
                $host['cpu_total'],
                $host['cpu_usage'],
                $host['memory_total'],
                $host['memory_usage'],
                $diskUsageText,
                $host['operating_system'],
                $status,
                $host['host_groups']
            ];

            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }
}
