<?php
namespace tpext\myadmin\admin\controller;

use think\Controller;
use tpext\builder\common\Builder;
use tpext\common\Extension;
use tpext\common\ExtLoader;
use tpext\common\Tool;
use tpext\myadmin\admin\model\AdminPermission;

class Auth extends Controller
{
    public function index()
    {
        $appPath = app()->getRootPath() . 'application' . DIRECTORY_SEPARATOR . 'admin/controller';

        $modControllers = [];
        $baseControllers = $this->scanControllers($appPath);

        if (!empty($baseControllers)) {
            $modControllers['app']['controllers'] = $this->scanControllers($appPath);
            $modControllers['app']['title'] = '基础';
        }

        $modControllers = array_merge($modControllers, $this->scanextEnsionControllers());

        ksort($modControllers);

        $data = [];

        $builder = Builder::getInstance('权限管理', '动作设置');

        $pagezise = 9999;

        $table = $builder->table();

        foreach ($modControllers as $key => $modController) {

            $row = [
                'id' => $key,
                'controller' => '<lable class="label label-success">' . $modController['title'] . '<label/>',
                'action' => '',
                'url' => '',
                '_url' => '',
                'action_name' => '',
                'action_type' => '',
            ];

            $data[] = $row;

            foreach ($modController['controllers'] as $controller) {

                $contrl = preg_replace('/.+?\\\controller\\\(\w+)$/', '$1', $controller);

                $row_ = array_merge($row, ['controller' => $controller, 'action_name' => $contrl, 'action_type' => '', 'action' => '————']);

                $data[] = $row_;

                $reflectionClass = new \ReflectionClass($controller);

                $methods = $this->getMethods($reflectionClass);

                foreach ($methods as $method) {
                    $url = url('/admin/' . strtolower($contrl) . '/' . $method);

                    $action_name = $method;

                    $action_names = [
                        'index' => '列表',
                        'list' => '列表',
                        'add' => '添加',
                        'edit' => '修改',
                        'update' => '更新',
                        'delete' => '删除',
                        'enable' => '启用',
                        'disable' => '禁用',
                        'status' => '状态',
                        'install' => '安装',
                        'uninstall' => '卸载',
                        'login' => '登录',
                        'logout' => '注销',
                        'dashbord' => '仪表盘',
                        'postback' => '列修改',
                        'upload' => '上传',
                        'download' => '下载',
                    ];

                    if (isset($action_names[$method])) {
                        $action_name = $action_names[$method];
                    }

                    $row__ = array_merge($row_, ['action' => '@' . $method, '_url' => '<a target="_blank" href="' . $url . '">' . $url . '</a>', 'url' => $url, 'action_name' => $action_name, 'action_type' => 1]);

                    $data[] = $row__;
                }
            }
        }

        foreach ($data as &$row) {
            if ($row['action'] != '') {
                $perm = AdminPermission::where(['controller' => $row['controller'], 'action' => $row['action']])->find();
                if ($perm) {
                    $row['action_type'] = $perm['action_type'];
                    $row['action_name'] = $perm['action_name'];
                    $row['id'] = $perm['id'];
                } else {
                    $row['id'] = AdminPermission::create([
                        'module_name' => $modController['title'],
                        'controller' => $row['controller'],
                        'action' => $row['action'],
                        'url' => $row['url'],
                        'action_type' => $row['action_type'],
                        'action_name' => $row['action_name'],
                    ]);
                }
            }

            if ($row['action'] == '' || $row['action'] == '————') {
                $row['action_type'] = '-1';
            }
        }

        $table->field('controller', '控制器');
        $table->field('action', '动作');
        $table->field('_url', 'url链接');
        $table->text('action_name', '动作名称')->mapClassWhen([''], 'hidden')->autoPost(url('postback'))->getWapper()->addStyle('max-width:100px');
        $table->radio('action_type', '权限')->options([0 => '否', 1 => '是'])->default(1)->autoPost(url('postback'))->mapClassWhen(['-1'], 'hidden')->getWapper()->addStyle('max-width:80px');

        $table->data($data);
        $table->paginator(count($data), $pagezise);
        $table->useToolbar(false);
        $table->useActionbar(false);

        return $builder->render();
    }

    public function postback()
    {
        $id = input('id/d', '');
        $name = input('name', '');
        $value = input('value', '');

        if (empty($id) || empty($name)) {
            $this->error('参数有误');
        }

        $allow = ['action_name', 'action_type'];

        if (!in_array($name, $allow)) {
            $this->error('不允许的操作');
        }

        $res = AdminPermission::where(['id' => $id])->update([$name => $value]);

        if ($res) {
            $this->success('修改成功');
        } else {
            $this->error('修改失败');
        }
    }

    /**
     * Undocumented function
     *
     * @param \ReflectionClass $reflection
     * @return array
     */
    private function getMethods($reflection)
    {
        $methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class == $reflection->getName() && !in_array($method->name, ['__construct', '_initialize'])) {
                $methods[] = $method->name;
            }
        }

        return $methods;
    }

    private function scanextEnsionControllers()
    {
        $controllers = [];

        $extensions = Extension::extensionsList();

        $installed = ExtLoader::getInstalled();

        foreach ($extensions as $key => $instance) {
            $is_enable = 0;

            if (!empty($installed)) {
                foreach ($installed as $ins) {
                    if ($ins['key'] == $key) {
                        $is_enable = $ins['enable'];
                        break;
                    }
                }
            }
            if (!$is_enable) {
                continue;
            }

            $mods = $instance->getModules();

            $namespaceMap = $instance->getNameSpaceMap();

            if (empty($namespaceMap) || count($namespaceMap) != 2) {
                $namespaceMap = Tool::getNameSpaceMap($key);
            }

            $namespace = rtrim($namespaceMap[0], '\\');
            $url_controller_layer = 'controller';

            if (!empty($mods)) {

                $controllers[$instance->getId()]['title'] = $instance->getTitle();

                foreach ($mods as $module => $modControllers) {

                    if (strtolower($module) != 'admin') {
                        continue;
                    }

                    foreach ($modControllers as $modController) {

                        if (false !== strpos($modController, '\\')) {
                            $class = '\\' . $module . '\\' . $modController . ltrim($module, '\\');

                        } else {
                            $class = '\\' . $module . '\\' . $url_controller_layer . '\\' . ucfirst($modController);
                        }

                        if (class_exists($namespace . $class)) {

                            $controllers[$instance->getId()]['controllers'][] = $namespace . $class;
                        }
                    }
                }
            }
        }

        return $controllers;
    }

    private function scanControllers($path, $controllers = [])
    {
        $dir = opendir($path);

        while (false !== ($file = readdir($dir))) {

            if (($file != '.') && ($file != '..')) {

                $sonDir = $path . DIRECTORY_SEPARATOR . $file;

                if (is_dir($sonDir)) {
                    //$controllers = array_merge($controllers, $this->scanControllers($sonDir));
                } else {
                    $sonDir = str_replace('/', '\\', $sonDir);

                    if (preg_match('/.+?\\\application(\\\admin\\\controller\\\.+?)\.php$/i', $sonDir, $mtches)) {
                        if (class_exists('app' . $mtches[1])) {
                            $controllers[] = 'app' . $mtches[1];
                        }
                    }
                }
            }
        }

        return $controllers;
    }
}