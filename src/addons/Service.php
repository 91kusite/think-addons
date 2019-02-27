<?php

namespace think\addons;

use think\Db;
use think\exception\PDOException;

/**
 * 插件服务
 * @package think\addons
 */
class Service
{
    const DS = DIRECTORY_SEPARATOR;

    /**
     * 导入SQL
     *
     * @param   string $name 插件名称
     * @return  boolean
     */
    public static function importsql($name, $type = null)
    {
        if (!$type) {
            return true;
        }
        $sqlFile = ADDON_PATH . $name . self::DS . $type . '.sql';
        if (is_file($sqlFile)) {
            $lines    = file($sqlFile);
            $templine = '';
            $db       = DB::connect();
            $db->startTrans();
            foreach ($lines as $line) {
                // 过滤注释,空行
                if (substr($line, 0, 2) == '--' || $line == '' || substr($line, 0, 2) == '/*') {
                    continue;
                }

                $templine .= $line;
                if (substr(trim($line), -1, 1) == ';') {
                    $templine = str_ireplace('__PREFIX__', config('database.prefix'), $templine);
                    $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
                    try {
                        $db->execute($templine);
                    } catch (PDOException $e) {
                        $db->rollback();
                        return $e;
                    }

                    $templine = '';
                }
            }
            $db->commit();
        }
        return true;
    }
}
