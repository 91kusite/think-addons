<?php
namespace think\command\addons;

use think\Console;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\Exception;
use think\facade\Config;
use think\facade\Env;

class Update extends Command
{
    const DS = DIRECTORY_SEPARATOR;
    /**
     * 更新插件包
     * @Author   MartinSun<syh@sunyonghong.com>
     * @DateTime 2018-10-31
     * @return   [type]                         [description]
     */
    protected function configure()
    {
        $this->setName('addons:update')
            ->addArgument('name', Argument::REQUIRED, 'set addons\'s name to update.', null)
            ->addOption('password', 'p', Option::VALUE_OPTIONAL, 'set addons\'s read password.', null)
            ->addOption('yes', 'y', Option::VALUE_NONE, 'make sure.', null)
            ->setDescription('Upgrade addons.');
    }
    protected function execute(Input $input, Output $output)
    {

        // 插件名称
        $addons_name = trim($input->getArgument('name'));
        if (!$addons_name) {
            $output->writeln('<error>Please set addons\'s name.</error>');
            return false;
        }
        // 该插件安装目录
        $addons_path = ADDON_PATH . $addons_name;
        if (!is_dir($addons_path)) {
            $output->writeln('<error>Uninstall addons error:' . $addons_name . ' not found!</error>');
            return false;
        }

        // 获取当前已经安装的版本

        $info    = Config::parse($addons_path . self::DS . 'info.ini', '', "addon-info-{$addons_name}");
        $version = $info['version'];

        // 检测最新版本
        // 插件包检测
        $zip_path = Env::get('root_path') . 'public' . self::DS . 'addons';
        $zips     = glob($zip_path . self::DS . $addons_name . '.*.*.*.zip');

        $update_zip = null;
        for ($i = 0; $i < count($zips); $i++) {
            $file_basename = basename($zips[$i], '.zip');
            $_version = substr($file_basename, stripos($file_basename, '.') + 1);
            if ($_version > $version) {
                $update_zip = $file_basename;
                break;
            }
        }

        if (!$update_zip) {
            $output->writeln('<info>The current version is up to date.</info>');
            return false;
        }

        // 是否确认安装
        if (!$input->hasOption('yes')) {
            // 提示是否确认
            if (!$output->confirm($input, 'Update ' . $addons_name . ' addons from ' . $version . ' to ' . $_version . ' ,sure?', false)) {
                $output->writeln("<info>User cancel.</info>");
                return false;
            }
        }

        // 备份
        $packname = $this->backupToAddons($output, $zip_path, $addons_name);
        $output->writeln("<info>Please waiting...</info>");
        // 开始解包命令处理
        $command_params = ['public' . self::DS . 'addons' . self::DS . $update_zip];
        if ($input->hasOption('password')) {
            // 存在解包命令
            $command_params[] = '-p=' . $input->getOption('password');
        }
        $outpath          = implode(self::DS, ['src', 'addons', $addons_name]);
        $command_params[] = '--outpath=' . $outpath;
        // 执行解包
        try {
            $log = [];
            Console::call('zip:unpack', $command_params);
            // 当前安装包信息
            $info_file = ADDON_PATH . $addons_name . self::DS . 'info.ini';
            if (!is_file($info_file)) {
                // 删除目录及文件
                $this->remove(ADDON_PATH . $addons_name);
                // 恢复备份
                $this->rollbackToAddons($zip_path, $packname, $addons_name);
                throw new Exception('install error');
            }

            // 加载配置文件
            $info              = Config::parse($info_file, '', "addon-info-{$addons_name}");
            $varsion           = $info['version'];
            $log[$addons_name] = $varsion;
        } catch (Exception $e) {
            $output->writeln("<error>Rollback addons error:" . $addons_name . ".Please try again.</error>");
            $log = [];
            return false;
        } finally {
            if ($log) {
                // 更新插件安装日志
                $installed_file = ADDON_PATH . 'addons.lock';
                if (!is_file($installed_file)) {
                    file_put_contents($installed_file, '<?php return []; ?>');
                }
                $default = include $installed_file;
                $log     = array_merge($default, $log);
                file_put_contents($installed_file, "<?php \r\n return " . var_export_min($log, true) . " \r\n ?>");
                $output->writeln("<info>Update " . $addons_name . " addons from " . $version . " to " . $_version . " success!</info>");
            }
        }

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

    /**
     * 备份插件
     * @Author   Martinsun<syh@sunyonghong.com>
     * @DateTime 2019-02-26
     * @return   [type]                         [description]
     */
    protected function backupToAddons($output, $savepath, $savename)
    {
        $output->writeln("<info>Please waiting...</info>");
        // 备份当前插件
        $output->writeln("<info>Backup addons: " . $savename . "...</info>");
        $command_params = [];
        // 包名
        $packname         = $savename . '.back.' . date('YmdHis') . '.zip';
        $command_params[] = $packname;
        // 打包路径
        $command_params[] = implode(self::DS, ['src', 'addons', $savename]);
        // 包保存路径
        $outpath          = 'public' . self::DS . 'addons' . self::DS;
        $command_params[] = '--outpath=' . $outpath;
        // 执行解包
        try {
            Console::call('zip:pack', $command_params);
            // 检测是否已经备份
            if (is_file($savepath . self::DS . $packname)) {
                return $packname;
            }
        } catch (\Exception $e) {
            $output->writeln("<error>Backup error addons:" . $savename . ".Please try again.</error>");
        }
        return false;
    }

    /**
     * 恢复插件
     * @Author   Martinsun<syh@sunyonghong.com>
     * @DateTime 2019-02-26
     * @return   [type]                         [description]
     */
    protected function rollbackToAddons($savepath, $packname, $basename)
    {
        if ($packname !== false) {
            $command_params   = [];
            $command_params[] = 'public' . self::DS . 'addons' . self::DS . $packname;
            $command_params[] = '--outpath=' . implode(self::DS, ['src', 'addons', $basename]);
            Console::call('zip:unpack', $command_params);
            if (is_dir(ADDON_PATH . $basename)) {
                unlink($savepath . self::DS . $packname);
            }
        }
    }

}
