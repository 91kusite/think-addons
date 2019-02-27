<?php
namespace think\command\addons;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Env;
USE think\facade\Config;

class Remove extends Command
{
    const DS = DIRECTORY_SEPARATOR;
    /**
     * 移除插件包
     * @Author   MartinSun<syh@sunyonghong.com>
     * @DateTime 2019-02-26
     * @return   [type]                         [description]
     */
    protected function configure()
    {
        $this->setName('addons:remove')
            ->addArgument('name', Argument::REQUIRED, 'set addons\'s name to uninstall.', null)
            ->addOption('yes', 'y', Option::VALUE_NONE, 'make sure.', null)
            ->setDescription('Uninstall addons.');
    }
    protected function execute(Input $input, Output $output)
    {
        // 插件名称
        $addons_name = trim($input->getArgument('name'));
        // 查找插件是否已经安装
        $addons_path = ADDON_PATH . $addons_name;
        if (!is_dir($addons_path)) {
            $output->writeln('<error>Uninstall addons error:' . $addons_name . ' not found!</error>');
            return false;
        }
        // 检测插件状态
        $info           = Config::parse($addons_path.self::DS.'info.ini', '', "addon-info-{$addons_name}");
        $state        = $info['state'];
        if ($state != '0') {
            $output->writeln('<error>Uninstall addons error:' . $addons_name . ' is busy!</error>');
            return false;
        }

        // 是否确认卸载
        if (!$input->hasOption('yes')) {
            // 提示是否确认
            if (!$output->confirm($input, 'Are you sure remove ' . $addons_name . '\'s addons?', false)) {
                $output->writeln("<info>User cancel.</info>");
                return false;
            }
        }
        try {
            $this->remove($addons_path);
            // 更新插件安装日志
            $log                = [];
            $log[$addons_name] = '';
        } catch (\Exception $e) {
            $output->writeln("<error>Uninstall addons error:" . $addons_name . ".Please try again.</error>");
            $log = [];
            return false;
        } finally {
            if ($log) {
                // 更新插件安装日志
                $installed_file = ADDON_PATH.'addons.lock';
                if (!is_file($installed_file)) {
                    file_put_contents($installed_file, '<?php return []; ?>');
                }
                $default = include $installed_file;
                $log     = array_diff_key($default, $log);
                file_put_contents($installed_file, "<?php \r\n return " . var_export_min($log, true) . " \r\n ?>");
            }
        }
        $output->writeln("<info>Remove addons " . $addons_name . " success!</info>");

    }

    /**
     * 执行删除目录和文件的方法
     * @Author   MartinSun<syh@sunyonghong.com>
     * @DateTime 2019-02-26
     * @param    string     $path 需要删除的目录或文件
     * @return
     */
    protected function remove($path)
    {
        // 打开目录
        $dh = opendir($path);
        // 循环读取目录
        while (($file = readdir($dh)) !== false) {
            // 过滤掉当前目录'.'和上一级目录'..'
            if ($file == '.' || $file == '..') {
                continue;
            }

            // 如果该文件是一个目录，则进入递归
            if (is_dir($path . '/' . $file)) {
                $this->remove($path . '/' . $file);
            } else {
                // 如果不是一个目录，则将其删除
                unlink($path . '/' . $file);
            }
        }
        // 退出循环后(此时已经删除所有了文件)，关闭目录并删除
        closedir($dh);
        rmdir($path);
    }

}
