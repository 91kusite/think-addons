<?php
namespace think\command\addons;

use think\Console;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Env;

class Backup extends Command
{
    const DS = DIRECTORY_SEPARATOR;
    /**
     * 插件包备份
     * @Author   MartinSun<syh@sunyonghong.com>
     * @DateTime 2019-02-26
     * @return   [type]                         [description]
     */
    protected function configure()
    {
        $this->setName('addons:backup')
            ->addArgument('name', Argument::REQUIRED, 'set addons\'s name to backup.', null)
            ->addOption('password', 'p', Option::VALUE_OPTIONAL, 'set addons\'s password.', null)
            ->setDescription('Backup addons.');
    }
    protected function execute(Input $input, Output $output)
    {
        // 插件名称
        $addons_name = trim($input->getArgument('name'));

        if (!$addons_name) {
            $output->writeln('<error>Please set addons\'s name.</error>');
            return false;
        }
        // 查找插件是否已经安装
        $addons_path = ADDON_PATH.$addons_name;
        if (!is_dir($addons_path)) {
            $output->writeln('<error>Backup addons error:' . $addons_name . ' not found!</error>');
            return false;
        }
        $output->writeln("<info>Please waiting...</info>");
        // 开始打包命令处理
        $command_params = [];
        // 包名
        $command_params[] = $addons_name . '.back.' . date('YmdHis') . '.zip';
        // 打包路径
        $command_params[] = implode(self::DS, ['src', 'addons', $addons_name]);
        if ($input->hasOption('password')) {
            // 设置读取密码
            $command_params[] = '--password=' . $input->getOption('password');
        }
        // 包保存路径
        $outpath          = 'public' . self::DS . 'addons' . self::DS;
        $command_params[] = '--outpath=' . $outpath;
        // 执行解包
        try {
            Console::call('zip:pack', $command_params);
        } catch (\Exception $e) {
            $output->writeln("<error>Backup error addons:" . $addons_name . ".Please try again.</error>");
            return false;
        }
        $output->writeln("<info>Backup addons " . $addons_name . " success!</info>");

    }

}
