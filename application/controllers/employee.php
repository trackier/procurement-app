<?php


use Framework\{Registry, TimeZone, ArrayMethods};
use Shared\Services\Db;

class Employee extends Shared\Controller {

	/**
	 * @before _secure
	 * [PUBLIC] This function will add employee
	 * - Return message
	 * @author Himanshu Rao <himanshurao@trackier.com>
	 */
	public function add(){
		$this->seo(["title" => "Add Employee Details"]); 
		$view = $this->getActionView();
		$departments = \Models\Department::selectAll([], [], ['maxTimeMS' => 5000, 'limit' => 5000, 'direction' => 'desc', 'order' => ['created' => -1]]);
		$employees = User::selectAll([], [], ['maxTimeMS' => 5000, 'direction' => 'desc', 'order' => ['created' => -1]]);
		try {
			if ($this->request->isPost()) {
				$data = $this->request->post('data', []);
				$data = array_merge($data, ['user_id' => $this->user->_id]);
				$data['password'] = "a94a8fe5ccb19ba61c4c0873d391e987982fbbd3";
				$employee = new User($data);
				$employee->save();
				\Shared\Utils::flashMsg(['type' => 'success', 'text' => 'Employee Added successfully']);
				$this->redirect('/employee/manage');
				
			}
		} catch (\Exception $e) {
			\Shared\Utils::flashMsg(['type' => 'error', 'text' => $e->getMessage()]);
		}
		$view->set([
			'users' => $employees ?? [],
			'departments' => $departments ?? [],
		]);
	}

	/**
	 * @before _secure
	 * [PUBLIC] This function will find employee base on query
	 * @author Himanshu Rao <himanshurao@trackier.com>
	 */
	public function manage() {
		$this->seo(["title" => "Manage Employees"]); 
		$view = $this->getActionView();

		$query = [];
		$searchKeyType = strtolower($this->request->get('type'));
		$searchValue = $this->request->get('search');
		switch ($searchKeyType) {
			case 'name':
				$query = array_merge($query, ['name' => Db::convertType($searchValue, 'regex')]);
				break;

			case 'emp_id':
				$query = array_merge($query, ['emp_id' => $searchValue]);
				break;

			case 'phone':
				$query = array_merge($query, ['phone' => Db::convertType($searchValue, 'regex')]);
				break;
		
			case 'email':
				$query = array_merge($query, ['email' => Db::convertType($searchValue, 'regex')]);
				break;
		}

		$employees = User::selectAll($query, [], ['maxTimeMS' => 5000, 'direction' => 'desc', 'order' => ['created' => -1]]);
		$total = $count = User::count($query);

		$view->set([
			'employees' => $employees ?? [],
			'search' => $this->request->get('search', ''),
			'type' => $this->request->get('type', 'name')
		]);
	}

	/**
	 * @before _secure
	 * [PUBLIC] This function will find and delete employee by id
	 * @author Himanshu Rao <himanshurao@trackier.com>
	 */
	public function delete($id = null) {
		$view = $this->getActionView();
	
		$employee = User::findById($id);
		if (!$employee) {
			return $view->set('message', ['type' => 'error', 'text' => 'No Employee found!']);
		}
		$msg = "";
		try {
			$employee->delete();
			$msg = 'Employee deleted successfully!';
			$this->redirect('/employee/manage');
		} catch (\Exception $e) {
			$msg = ['type' => 'error', 'text' => 'Something went wrong. Please Try Again'];
		}
		$view->set('message', $msg);
	}

	/**
	 * @before _secure
	 * [PUBLIC] This function will find and edit employee
	 * @author Himanshu Rao <himanshurao@trackier.com>
	 */
	public function edit($id = null) {
		$this->seo(["title" => "Update Employee Details"]); 
		$view = $this->getActionView();
		$users = User::selectAll([], [], ['maxTimeMS' => 5000, 'direction' => 'desc', 'order' => ['created' => -1]]);
		if (!$id) {
			$this->_404();
		}
		$employee = User::findById($id);
		if (!$employee) {
			return $view->set('message', ['type' => 'error', 'text' => 'No Employee found!']);
		}
		try {
			if ($this->request->isPost()) {
				$data = $this->request->post('data', []);
				foreach(['name', 'emp_id', 'email', 'phone', 'department', 'gchatwebhook', 'rm_id'] as $value) {
					if (isset($data[$value])) {
						$employee->$value = $data[$value];
					}
				}
				$employee->save();
				$view->set('message', ['type' => 'success', 'text' => 'Employee Edited successfully']);
				
			}
		} catch (\Exception $e) {
			$view->set('message', ['type' => 'error', 'text' => $e->getMessage()]);
		}
		$departments = \Models\Department::selectAll([], [], ['maxTimeMS' => 5000, 'limit' => 5000, 'direction' => 'desc', 'order' => ['created' => -1]]);

		$view->set('employee', $employee);
		$view->set('users', $users);
		$view->set('departments', $departments);
	}
}