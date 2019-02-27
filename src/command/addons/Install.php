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
        $basename = basename($addons_name);

        $is_sure = $packname = false;
        // 检测是否已经安装
        if (is_dir(ADDON_PATH . $basename)) {
            $output->writeln('<info>Nothing to do,because the addons of ' . $basename . ' is installed.</info>');
            // 提示是否覆盖
            if (!$output->confirm($input, 'Already installed, confirm replacement?', false)) {
                $output->writeln("<info>User cancel.</info>");
                return false;
            }
            // 备份已安装版本
            $packname = $this->backupToAddons($output, $zip_path, $basename);
            $is_sure  = true;
        }

        // 检测是否备份安装
        if (!$input->hasOption('back')) {
            // 版本规定
            if ($input->hasOption('rversion')) {
                // 指定版本
                $list = glob($zip_path . self::DS . $basename . '.' . $input->getOption('rversion') . '.zip');
            } else {
                // 获取最新版本
                $list = glob($zip_path . self::DS . $addons_name . '.*');
            }
            // 取得最新版本或指定版本
            if ($list) {
                rsort($list);
                $addons_name = basename($list[0], '.zip');
            }
        } else {
            // 拼接备份包存放位置
            $addons_name = $addons_name . '.back.' . $input->getOption('back');
        }
        // zip包识别
        (stripos($addons_name, '.zip') === false) && $addons_name .= '.zip';
        // 检测安装包是否存在
        if (!is_file($zip_path . self::DS . $addons_name)) {
            $output->writeln('<error>addons install error :' . $basename . ' not found!</error>');
            return false;
        }

        // 是否确认安装
        if (!$is_sure && !$input->hasOption('yes')) {
            // 提示是否确认
            if (!$output->confirm($input, 'Install ' . $basename . ' addons ,sure?', false)) {
                $output->writeln("<info>User cancel.</info>");
                return false;
            }
        }
        $output->writeln("<info>Please waiting...</info>");
        // 执行解包
        try {
            // 开始解包命令处理
            $command_params = ['public' . self::DS . 'addons' . self::DS . $addons_name];
            if ($input->hasOption('password')) {
                // 存在解包命令
                $command_params[] = '--password=' . $input->getOption('password');
            }
            // 输出目录
            $outpath          = implode(self::DS, ['src', 'addons', $basename]);
            $command_params[] = '--outpath=' . $outpath;
            $log              = [];
            Console::call('zip:unpack', $command_params);
            // 当前安装包信息
            $info_file = ADDON_PATH . $basename . self::DS . 'info.ini';
            if (!is_file($info_file)) {
                // 删除目录及文件
                $this->remove(ADDON_PATH . $basename);
                // 恢复备份
                $this->rollbackToAddons($zip_path, $packname, $basename);
                throw new Exception('install error');
            }

            // 加载配置文件
            $info           = Config::parse($info_file, '', "addon-info-{$basename}");
            $varsion        = $info['version'];
            $log[$basename] = $varsion;
        } catch (Exception $e) {
            $output->writeln("<error>Install error addons:" . $basename . ",Please try again.</error>");
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
                $output->writeln("<info>Install addons " . $basename . " success!</info>");
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
                // 删除旧目录
                $this->remove(ADDON_PATH . $savename);
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
