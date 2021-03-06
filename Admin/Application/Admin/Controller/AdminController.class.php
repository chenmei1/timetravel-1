<?php
// +----------------------------------------------------------------------
// | OneDream 后台控制器基类
// +----------------------------------------------------------------------
// | Copyright (c) 2013-2014 http://www.coolhots.net All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: CoolHots <coolhots@outlook.com>
// +----------------------------------------------------------------------
// | Date: 2014-4-7
// +----------------------------------------------------------------------
namespace Admin\Controller;

use Think\Controller;

class AdminController extends Controller {
	/**
	 * 后台控制器初始化
	 */
	protected function _initialize() {
		// 获取当前用户ID
		define ( 'UID', is_login () );
		if (! UID) { // 还没登录 跳转到登录页面
			$this->redirect ( 'Public/login' );
		}
		/* 读取数据库中的配置 */
		$config = S ( 'DB_CONFIG_DATA' );
		if (! $config) {
			$config = D ( 'Config' )->lists ();
			S ( 'DB_CONFIG_DATA', $config );
		}
		C ( $config ); // 添加配置
		// 权限验证
		$this->checkAuth();
	}
	// 权限验证
	protected function checkAuth() {
		// 模块名
		$MODULE_NAME = strtolower ( MODULE_NAME );
		// 控制器名
		$CONTROLLER_NAME = strtolower ( CONTROLLER_NAME );
		// 动作名
		$ACTION_NAME = strtolower ( ACTION_NAME );
		$auth_url = $MODULE_NAME . '/' . $CONTROLLER_NAME . '/' . $ACTION_NAME;
		$userdata = session ( 'user_auth' );
		// roleid 为1是系统管理员，显示所有菜单
		if ($userdata ['roleid'] != 1) {
			$map ['url'] = array (
					'like',
					 $auth_url . '%' 
			);
			$auth = D ( 'Auth' )->where ( $map )->field('id')->select ();
			$rolauth=session ( 'roleauth' );
			if ($auth) {
				if ( !in_array ( $auth [0]['id'], $rolauth )){
					$this->error ( '对不起，您无权操作！' );
					
				}
			} 
		}
		
	}
	/**
	 * 通用分页列表数据集获取方法
	 *
	 * 可以通过url参数传递where条件,例如: index.html?name=asdfasdfasdfddds
	 * 可以通过url空值排序字段和方式,例如: index.html?_field=id&_order=asc
	 * 可以通过url参数r指定每页数据条数,例如: index.html?r=5
	 *
	 * @param sting|Model $model
	 *        	模型名或模型实例
	 * @param array $where
	 *        	where查询条件(优先级: $where>$_REQUEST>模型设定)
	 * @param array|string $order
	 *        	排序条件,传入null时使用sql默认排序或模型属性(优先级最高);
	 *        	请求参数中如果指定了_order和_field则据此排序(优先级第二);
	 *        	否则使用$order参数(如果$order参数,且模型也没有设定过order,则取主键降序);
	 *        	
	 * @param array $base
	 *        	基本的查询条件
	 * @param boolean $field
	 *        	单表模型用不到该参数,要用在多表join时为field()方法指定参数
	 *        	
	 * @return array false
	 */
	protected function lists($model, $where = array(), $order = '', $base = array('status'=>array('egt',0)), $field = true) {
		$options = array ();
		$REQUEST = ( array ) I ( 'request.' );
		if (is_string ( $model )) {
			$model = M ( $model );
		}
		
		$OPT = new \ReflectionProperty ( $model, 'options' );
		$OPT->setAccessible ( true );
		
		$pk = $model->getPk ();
		if ($order === null) {
			// order置空
		} else if (isset ( $REQUEST ['_order'] ) && isset ( $REQUEST ['_field'] ) && in_array ( strtolower ( $REQUEST ['_order'] ), array (
				'desc',
				'asc' 
		) )) {
			$options ['order'] = '`' . $REQUEST ['_field'] . '` ' . $REQUEST ['_order'];
		} elseif ($order === '' && empty ( $options ['order'] ) && ! empty ( $pk )) {
			$options ['order'] = $pk . ' desc';
		} elseif ($order) {
			$options ['order'] = $order;
		}
		unset ( $REQUEST ['_order'], $REQUEST ['_field'] );
		
		$options ['where'] = array_filter ( array_merge ( ( array ) $base, /*$REQUEST,*/ ( array ) $where ), function ($val) {
			if ($val === '' || $val === null) {
				return false;
			} else {
				return true;
			}
		} );
		if (empty ( $options ['where'] )) {
			unset ( $options ['where'] );
		}
		$options = array_merge ( ( array ) $OPT->getValue ( $model ), $options );
		$total = $model->where ( $options ['where'] )->count ();
		
		if (isset ( $REQUEST ['r'] )) {
			$listRows = ( int ) $REQUEST ['r'];
		} else {
			$listRows = C ( 'LIST_ROWS' ) > 0 ? C ( 'LIST_ROWS' ) : 10;
		}
		$page = new \Think\Page ( $total, $listRows, $REQUEST );
		if ($total > $listRows) {
			$page->setConfig ( 'theme', '%FIRST% %UP_PAGE% %LINK_PAGE% %DOWN_PAGE% %END% %HEADER%' );
		}
		$p = $page->show ();
		$this->assign ( '_page', $p ? $p : '' );
		$this->assign ( '_total', $total );
		$options ['limit'] = $page->firstRow . ',' . $page->listRows;
		
		$model->setProperty ( 'options', $options );
		
		return $model->field ( $field )->select ();
	}
	
	/**
	 * 对数据表中的单行或多行记录执行修改 GET参数id为数字或逗号分隔的数字
	 *
	 * @param string $model
	 *        	模型名称,供M函数使用的参数
	 * @param array $data
	 *        	修改的数据
	 * @param array $where
	 *        	查询时的where()方法的参数
	 * @param array $msg
	 *        	执行正确和错误的消息 array('success'=>'','error'=>'',
	 *        	'url'=>'','ajax'=>false)
	 *        	url为跳转页面,ajax是否ajax方式(数字则为倒数计时秒数)
	 *        	
	 */
	final protected function editRow($model, $data, $where, $msg) {
		$id = array_unique ( ( array ) I ( 'id', 0 ) );
		$id = is_array ( $id ) ? implode ( ',', $id ) : $id;
		$where = array_merge ( array (
				'id' => array (
						'in',
						$id 
				) 
		), ( array ) $where );
		$msg = array_merge ( array (
				'success' => '操作成功！',
				'error' => '操作失败！',
				'url' => '',
				'ajax' => IS_AJAX 
		), ( array ) $msg );
		if (M ( $model )->where ( $where )->save ( $data ) !== false) {
			$this->success ( $msg ['success'], $msg ['url'], $msg ['ajax'] );
		} else {
			$this->error ( $msg ['error'], $msg ['url'], $msg ['ajax'] );
		}
	}
	
	/**
	 * 禁用条目
	 * @param string $model 模型名称,供D函数使用的参数
	 * @param array  $where 查询时的 where()方法的参数
	 * @param array  $msg   执行正确和错误的消息,可以设置四个元素 array('success'=>'','error'=>'', 'url'=>'','ajax'=>false)
	 *                     url为跳转页面,ajax是否ajax方式(数字则为倒数计时秒数)
	 *
	 */
	protected function forbid ( $model , $where = array() , $msg = array( 'success'=>'状态禁用成功！', 'error'=>'状态禁用失败！')){
		$data    =  array('status' => 0);
		$this->editRow( $model , $data, $where, $msg);
	}
	
	/**
	 * 恢复条目
	 * @param string $model 模型名称,供D函数使用的参数
	 * @param array  $where 查询时的where()方法的参数
	 * @param array  $msg   执行正确和错误的消息 array('success'=>'','error'=>'', 'url'=>'','ajax'=>false)
	 *                     url为跳转页面,ajax是否ajax方式(数字则为倒数计时秒数)
	 *
	 */
	protected function resume (  $model , $where = array() , $msg = array( 'success'=>'状态恢复成功！', 'error'=>'状态恢复失败！')){
		$data    =  array('status' => 1);
		$this->editRow(   $model , $data, $where, $msg);
	}
	
}