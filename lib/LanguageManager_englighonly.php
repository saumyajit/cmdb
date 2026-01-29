<?php

namespace Modules\ZabbixCmdb\Lib;

class LanguageManager {
    private const ZBX_DEFAULT_LANG = 'en_US';

    private static $currentLanguage = null;

    private static $translations = [
        'en_US' => [
            'CMDB' => 'CMDB',
            'Configuration Management Database' => 'Configuration Management Database',
            'Search hosts...' => 'Search hosts...',
            'Search by hostname or IP' => 'Search by hostname or IP',
            'All Groups' => 'All Groups',
            'Select host group' => 'Select host group',
            'Host Name' => 'Host Name',
            'IP Address' => 'IP Address',
            'Interface Type' => 'Interface Type',
            'All Interfaces' => 'All Interfaces',
            'CPU Total' => 'CPU Total',
            'CPU Usage' => 'CPU Usage',
            'Memory Total' => 'Memory Total',
            'Memory Usage' => 'Memory Usage',
            'Host Group' => 'Host Group',
            'System Name' => 'System Name',
            'Architecture' => 'Architecture',
            'Operating System' => 'Operating System',
            'Kernel Version' => 'Kernel Version',
            'Agent' => 'Agent',
            'SNMP' => 'SNMP',
            'IPMI' => 'IPMI',
            'JMX' => 'JMX',
            'No hosts found' => 'No hosts found',
            'Loading...' => 'Loading...',
            'Search' => 'Search',
            'Clear' => 'Clear',
            'Total Hosts' => 'Total Hosts',
            'Host Groups' => 'Host Groups',
            'Host List' => 'Host List',
            'Search host groups...' => 'Search host groups...',
            'Enter group name' => 'Enter group name',
            'Group Name' => 'Group Name',
            'Host Count' => 'Host Count',
            'Active Hosts' => 'Active Hosts',
            'Search by group name' => 'Search by group name',
            'Search groups...' => 'Search groups...',
            'Status' => 'Status',
            'No groups found' => 'No groups found',
            'Empty Group' => 'Empty Group',
            'Active Group' => 'Active Group',
            'Basic Group' => 'Basic Group',
            'hosts' => 'hosts',
            'cores' => 'cores',
            'Invalid input parameters.' => 'Invalid input parameters.'
        ]
    ];

    /**
     * Force English explicitly
     */
    public static function detectLanguage() {
        if (self::$currentLanguage === null) {
            self::$currentLanguage = self::ZBX_DEFAULT_LANG;
        }
        return self::$currentLanguage;
    }

    /**
     * Translation helpers
     */
    public static function t($key) {
        return self::$translations[self::ZBX_DEFAULT_LANG][$key] ?? $key;
    }

    public static function tf($key, ...$args) {
        return sprintf(self::t($key), ...$args);
    }

    public static function getCurrentLanguage() {
        return self::ZBX_DEFAULT_LANG;
    }

    public static function resetLanguage() {
        self::$currentLanguage = null;
    }

    /**
     * Debug info (still useful for diagnostics)
     */
    public static function getLanguageDetectionInfo() {
        return [
            'forced_language' => self::ZBX_DEFAULT_LANG,
            'supported_locales' => array_keys(self::$translations)
        ];
    }

    /**
     * Date & time formatting (English only)
     */
    public static function formatDateTime($timestamp, $format = 'Y-m-d H:i:s') {
        return date($format, $timestamp);
    }

    public static function formatDate($timestamp, $format = 'Y-m-d') {
        return date($format, $timestamp);
    }

    public static function formatPeriod($type, $dateString) {
        $timestamp = is_string($dateString) ? strtotime($dateString) : $dateString;
        if ($timestamp === false) {
            return $dateString;
        }

        switch ($type) {
            case 'day':
            case 'daily':
                return date('Y-m-d', $timestamp);
            case 'week':
            case 'weekly':
                return date('Y', $timestamp) . ' Week ' . date('W', $timestamp);
            case 'month':
            case 'monthly':
                return date('Y-m', $timestamp);
            default:
                return date('Y-m-d', $timestamp);
        }
    }
}