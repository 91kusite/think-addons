<?php

namespace think\addons;

use app\common\library\Auth;
use think\facade\Config;
use think\facade\Hook;
use think\facade\Lang;
use think\Loader;
use think\Request;

/**
 * 插件基类控制器
 * @package think\addons
 */
class Controller extends \think\Controller
{

    // 当前插件操作
    protected $addon      = null;
    protected $controller = null;
    protected $action     = null;
    // 当前template
    protected $template;

    /**
     * 无需登录的方法,同时也就不需要鉴权了
     * @var array
     */
    protected $noNeedLogin = ['*'];

    /**
     * 无需鉴权的方法,但需要登录
     * @var array
     */
    protected $noNeedRight = ['*'];

    /**
     * 权限Auth
     * @var Auth
     */
    protected $auth = null;

    /**
     * 布局模板
     * @var string
     */
    protected $layout = null;

    /**
     * 架构函数
     * @param Request $request Request对象
     * @access public
     */
    public function __construct(Request $request = null)
    {
        // 生成request对象
        $this->request = $request;

        //移除HTML标签
        $this->request->filter('strip_tags');

        // 是否自动转换控制器和操作名
        $convert = Config::get('url_convert');

        $filter = $convert ? 'strtolower' : 'trim';
        // 处理路由参数
        $param      = $this->request->param();
        $addon      = $param['addon'];
        $controller = $this->request->controller();
        $action     = $this->request->action();

        $this->addon      = $addon ? call_user_func($filter, $addon) : '';
        $this->controller = $controller ? call_user_func($filter, $controller) : 'index';
        $this->action     = $action ? call_user_func($filter, $action) : 'index';

        // 重置配置
        Config::set('template.view_path', ADDON_PATH . $this->addon . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR);

        // 父类的调用必须放在设置模板路径之后
        parent::__construct();
    }

    protected function _initialize()
    {
        // 渲染配置到视图中
        $config = get_addon_config($this->addon);
        $this->view->assign("addnons_config", $config);
    }

    /**
     * 加载模板输出
     * @access protected
     * @param string $template 模板文件名
     * @param array $vars 模板输出变量
     * @param array $replace 模板替换
     * @param array $config 模板参数
     * @return mixed
     */
    protected function fetch($template = '', $vars = [], $replace = [], $config = [])
    {
        $controller = Loader::parseName($this->controller);
        if ('think' == strtolower(Config::get('template.type')) && $controller && 0 !== strpos($template, '/')) {
            $depr     = Config::get('template.view_depr');
            $template = str_replace(['/', ':'], $depr, $template);
            if ('' == $template) {
                // 如果模板文件名为空 按照默认规则定位
                $template = str_replace('.', DIRECTORY_SEPARATOR, $controller) . $depr . $this->action;
            } elseif (false === strpos($template, $depr)) {
                $template = str_replace('.', DIRECTORY_SEPARATOR, $controller) . $depr . $template;
            }
        }
        return parent::fetch($template, $vars, $replace, $config);
    }

}
