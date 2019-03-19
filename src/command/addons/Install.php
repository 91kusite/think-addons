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
use think\addons\Service;

class Install extends Command
{
    const DS = DIRECTORY_SEPARATOR;
    /**
     * 安装插件包
     * @Author   MartinSun<syh@sunyonghong.com>
     * @DateTime 2019-02-26
     * @return   [type]                         [description]
     */
    protected function configure()
    {
        $this->setName('addons:install')
            ->addArgument('name', Argument::REQUIRED, 'set addons\'s name to install.', null)
            ->addOption('password', 'p', Option::VALUE_OPTIONAL, 'set addons\'s read password.', null)
            ->addOption('rversion', 'r', Option::VALUE_OPTIONAL, 'set addons\'s version.', null)
            ->addOption('yes', 'y', Option::VALUE_NONE, 'make sure.', null)
            ->addOption('back', null, Option::VALUE_OPTIONAL, 'set addons\'s backup number.', null)
            ->setDescription('Install addons.')
            ->setHelp(<<<EOT
The <info>addons:install</info> command installs the addons, which can install the specified version or use backup installation

<info>php console addons:install test</info>
<info>php console addons:install test -p 123456</info>
<info>php console addons:install test -r 1.0.0</info>
<info>php console addons:install test --back 20190227110637</info>

EOT
            );
    }
    protected function execute(Input $input, Output $output)
    {
        // 插件包检测
        $zip_path    = Env::get('root_path') . 'public' . self::DS . 'addons';
        $addons_name = trim($input->getArgument('name'));

        if (!$addons_name) {
            $output->writeln('<error>Please set addons\'s name.</error>');
            return false;
        }

        $is_sure = $packname = false;
        // 检测是否已经安装
        if (is_dir(ADDON_PATH . $addons_name)) {
            $output->writeln('<info>Nothing to do,because the addons of ' . $addons_name . ' is installed.</info>');
            // 提示是否覆盖
            if (!$output->confirm($input, 'Already installed, confirm replacement?', false)) {
                $output->writeln("<info>User cancel.</info>");
                return false;
            }
            // 备份已安装版本
            $packname = $this->backupToAddons($output, $zip_path, $addons_name);
            if($packname === false){
                return false;
            }
            $is_sure  = true;
        }

        $zip_name = null;
        // 检测是否备份安装
        if (!$input->hasOption('back')) {
            // 版本规定
            if ($input->hasOption('rversion')) {
                // 指定版本
                $list = glob($zip_path . self::DS . $addons_name . '.' . $input->getOption('rversion') . '.zip');
            } else {
                // 获取最新版本
                $list = glob($zip_path . self::DS . $addons_name . '.*');
            }
            // 取得最新版本或指定版本
            if ($list) {
                rsort($list);
                $zip_name = basename($list[0]);
            }
        } else {
            // 拼接备份包存放位置
            $zip_name = $addons_name . '.back.' . $input->getOption('back');
        }
        // zip包识别
        (stripos($zip_name, '.zip') === false) && $zip_name .= '.zip';
        // 检测安装包是否存在
        if (!$zip_name || !is_file($zip_path . self::DS . $zip_name)) {
            $output->writeln('<error>addons install error :' . $addons_name . ' not found!</error>');
            return false;
        }

        // 是否确认安装
        if (!$is_sure && !$input->hasOption('yes')) {
            // 提示是否确认
            if (!$output->confirm($input, 'Install ' . $addons_name . ' addons ,sure?', false)) {
                $output->writeln("<info>User cancel.</info>");
                return false;
            }
        }
        $output->writeln("<info>Please waiting...</info>");
        // 备份成功,删除目录及文件
        $packname && $this->remove(ADDON_PATH . $addons_name);
        // 执行解包
        try {
            // 开始解包命令处理
            $command_params = ['public' . self::DS . 'addons' . self::DS . $zip_name];
            if ($input->hasOption('password')) {
                // 存在解包命令
                $command_params[] = '--password=' . $input->getOption('password');
            }
            // 输出目录
            $command_params[] = '--outpath=' . implode(self::DS, ['src', 'addons', $addons_name]);
            $log              = [];
            Console::call('zip:unpack', $command_params);
            // 当前安装包信息
            $info_file = ADDON_PATH . $addons_name . self::DS . 'info.ini';
            if (!is_file($info_file)) {
                throw new Exception('install error');
            }

            $this->install($addons_name);

            Service::importsql($addons_name,'install');

            // 加载配置文件
            $info           = Config::parse($info_file, '', "addon-info-{$addons_name}");
            $varsion        = $info['version'];
            $log[$addons_name] = $varsion;
        } catch (Exception $e) {
            // 删除目录及文件
            $this->remove(ADDON_PATH . $addons_name);
            // 恢复备份
            $this->rollbackToAddons($zip_path, $packname, $addons_name);
            $output->writeln("<error>Install " . $addons_name . " addons error,Please try again.</error>");
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
                $output->writeln("<info>Install addons " . $addons_name . " success!</info>");
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
    protected function backupToAddons($output, $savepath, $addons_name)
    {
        $output->writeln("<info>Please waiting...</info>");
        // 备份当前插件
        $output->writeln("<info>Backup addons: " . $addons_name . "...</info>");
        $command_params = [];
        // 包名
        $packname         = $addons_name . '.back.' . date('YmdHis') . '.zip';
        $command_params[] = $packname;
        // 打包路径
        $command_params[] = implode(self::DS, ['src', 'addons', $addons_name]);
        // 包保存路径
        $outpath          = 'public' . self::DS . 'addons' . self::DS;
        $command_params[] = '--outpath=' . $outpath;
        // 执行解包
        try {
            Console::call('zip:pack', $command_params);
            // 检测是否已经备份
            if (is_file($savepath . self::DS . $packname)) {
                // 删除旧目录
                // $this->remove(ADDON_PATH . $addons_name);
                return $packname;
            }
        } catch (Exception $e) {
            $output->writeln("<error>Backup error addons:" . $addons_name . ".Please try again.</error>");
        }
        return false;
    }

    /**
     * 恢复插件
     * @Author   Martinsun<syh@sunyonghong.com>
     * @DateTime 2019-02-26
     * @return   [type]                         [description]
     */
    protected function rollbackToAddons($savepath, $packname, $addons_name)
    {
        if ($packname !== false) {
            $command_params   = [];
            $command_params[] = 'public' . self::DS . 'addons' . self::DS . $packname;
            $command_params[] = '--outpath=' . implode(self::DS, ['src', 'addons', $addons_name]);
            Console::call('zip:unpack', $command_params);
            if (is_dir(ADDON_PATH . $addons_name)) {
                unlink($savepath . self::DS . $packname);
            }
        }
    }

    /**
     * 执行插件内部的安装方法
     * @Author   Martinsun<syh@sunyonghong.com>
     * @DateTime 2019-03-08
     * @param    [type]                         $addons_name [description]
     * @return   [type]                                      [description]
     */
    protected function install($addons_name){
        
        try{
            $class = get_addon_class($addons_name);
            if (class_exists($class)) {
                $addon = new $class();
                $addon->install();
            }
        }catch(Exception $e){
            throw new Exception($e->getMessage());
        }
    }

}
