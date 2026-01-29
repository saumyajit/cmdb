<?php

namespace Modules\ZabbixCmdb\Actions;

use CController,
    CControllerResponseData,
    API;

require_once dirname(__DIR__) . '/lib/LanguageManager.php';
require_once dirname(__DIR__) . '/lib/ItemFinder.php';
use Modules\ZabbixCmdb\Lib\LanguageManager;
use Modules\ZabbixCmdb\Lib\ItemFinder;

class CmdbGroups extends CController {

    public function init(): void {
        // Compatible with Zabbix 6 and 7
        if (method_exists($this, 'disableCsrfValidation')) {
            $this->disableCsrfValidation(); // Zabbix 7
        } elseif (method_exists($this, 'disableSIDvalidation')) {
            $this->disableSIDvalidation(); // Zabbix 6
        }
    }

    protected function checkInput(): bool {
        $fields = [
            'sort' => 'string',
            'sortorder' => 'in ASC,DESC',
            'search' => 'string'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseData(['error' => LanguageManager::t('Invalid input parameters.')]));
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $sort = $this->getInput('sort', 'host_count');
        $sortorder = $this->getInput('sortorder', 'DESC');
        $search = $this->getInput('search', '');

        // Get the list of host groups
        $hostGroups = API::HostGroup()->get([
            'output' => ['groupid', 'name'],
            'sortfield' => 'name',
            'sortorder' => 'ASC'
        ]);

     	// Filtering groups that start with CUSTOMER/, PRODUCT/, or TYPE/
		$filteredGroups = [];
		foreach ($hostGroups as $group) {
			$name = $group['name'];
			if (strpos($name, 'CUSTOMER/') === 0 || 
				strpos($name, 'PRODUCT/') === 0 || 
				strpos($name, 'TYPE/') === 0) {
				$filteredGroups[] = $group;
			}
		}
		$hostGroups = $filteredGroups;
        
        // Filter groups if a search term is provided
        if (!empty($search)) {
            $hostGroups = array_filter($hostGroups, function($group) use ($search) {
                return stripos($group['name'], $search) !== false;
            });
        }

        // Get detailed information for each group
        $groupData = [];
        foreach ($hostGroups as $group) {
            // Retrieve hosts in the group
            $hosts = API::Host()->get([
                'output' => ['hostid', 'host', 'name', 'status'],
                'groupids' => [$group['groupid']],
                'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'type', 'main', 'available', 'error']
            ]);

            $hostCount = count($hosts);
            $totalCpu = 0;
            $totalMemory = 0;

            // Calculate total CPU and memory for the group
            foreach ($hosts as $host) {
                // Get the number of CPUs
                $cpuResult = ItemFinder::findCpuCount($host['hostid']);
                if ($cpuResult && $cpuResult['value'] !== null) {
                    $totalCpu += intval($cpuResult['value']);
                }

                // Get the total memory
                $memoryResult = ItemFinder::findMemoryTotal($host['hostid']);
                if ($memoryResult && $memoryResult['value'] !== null) {
                    $totalMemory += intval($memoryResult['value']);
                }
            }

            $groupData[] = [
                'groupid' => $group['groupid'],
                'name' => $group['name'],
                'host_count' => $hostCount,
                'total_cpu' => $totalCpu,
                'total_memory' => $totalMemory
            ];
        }

        // Sort the groups based on the specified parameters
        if (!empty($groupData)) {
            usort($groupData, function($a, $b) use ($sort, $sortorder) {
                $valueA = $a[$sort] ?? 0;
                $valueB = $b[$sort] ?? 0;

                // For numeric fields, ensure correct comparison
                if (in_array($sort, ['host_count', 'total_cpu', 'total_memory'])) {
                    $valueA = (int)$valueA;
                    $valueB = (int)$valueB;
                } else {
                    $valueA = (string)$valueA;
                    $valueB = (string)$valueB;
                }

                if ($sortorder === 'DESC') {
                    return $valueB <=> $valueA;
                } else {
                    return $valueA <=> $valueB;
                }
            });
        }

        $response = new CControllerResponseData([
            'title' => LanguageManager::t('Host Groups'),
            'groups' => $groupData,
            'sort' => $sort,
            'sortorder' => $sortorder,
            'search' => $search
        ]);
        
        // Explicitly set the response title (required for Zabbix 6.0)
        $response->setTitle(LanguageManager::t('Host Groups'));

        $this->setResponse($response);
    }
}
