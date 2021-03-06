<?php
 
/** 
 *	(c) 2017 uzERP LLP (support#uzerp.com). All rights reserved. 
 * 
 *	Released under GPLv3 license; see LICENSE. 
 **/

class MfoperationsController extends ManufacturingController {

	protected $version='$Revision: 1.14 $';
	protected $_templateobject;
	
	public function __construct($module=null,$action=null) {
		parent::__construct($module, $action);
		$this->_templateobject = new MFOperation();
		$this->uses($this->_templateobject);
	}

	public function index(){
		$errors=array();
		$s_data=array();
		if (isset($this->_data['stitem_id'])) {
			$stitem_id = $this->_data['stitem_id'];
		} elseif (isset($this->_data['Search']['stitem_id'])) {
			$stitem_id = $this->_data['Search']['stitem_id'];
		}

		if (!isset($stitem_id)) {
			$flash = Flash::Instance();
			$flash->addError('No Stock Item specified');
			sendTo('STItems'
					,'index'
					,$this->_modules);
			return;
		}
		
		$s_data['start_date/end_date'] = date(DATE_FORMAT);
		$s_data['stitem_id'] = $stitem_id;
		
		$this->view->set('stitem_id', $stitem_id);
		$transaction = new STItem();
		$transaction->load($stitem_id);
		$this->view->set('transaction',$transaction);
		$obsolete = $transaction->isObsolete();
		
		$this->setSearch('operationsSearch', 'useDefault', $s_data);

		self::showParts();
				
		$this->view->set('clickaction','view');
		
		$sidebar = new SidebarController($this->view);
		$sidebar->addList(
			'Actions',
			array(
				'allItems' => array('tag' => 'All Items'
								 ,'link' => array('modules'=>$this->_modules
												 ,'controller'=>'STItems'
												 ,'action'=>'index'
												 )
								 ),
				'thisItem' => array('tag' => 'Item detail'
								 ,'link' => array('modules'=>$this->_modules
												 ,'controller'=>'STItems'
												 ,'action'=>'view'
												 ,'id'=>$stitem_id
												 )
								 ),
				'new'=>array('tag'=>'New Operation'
							,'link'=>array('modules'=>$this->_modules
										  ,'controller'=>$this->name
										  ,'action'=>'new'
										  ,'stitem_id'=>$stitem_id
										  )
							)
				)
			);
		$this->view->register('sidebar',$sidebar);
		$this->view->set('sidebar',$sidebar);
	}

	public function delete(){
		$flash = Flash::Instance();
		//parent::delete('MFOperation');
		$errors = array();
		$data = array(
			'id' => $this->_data['id'],
			'end_date' => date(DATE_FORMAT)
		);
		$operation = MFOperation::Factory($data, $errors, 'MFOperation');
		if ((count($errors) > 0) || (!$operation->save())) {
			$errors[] = 'Could not delete operation';
		}
		if (count($errors) == 0) {
			$stitem = new STItem;
			if ($stitem->load($operation->stitem_id)) {
				//$stitem->calcLatestCost();
				if (!$stitem->rollUp(STItem::ROLL_UP_MAX_LEVEL)) {
					$errors[] = 'Could not roll-up latest costs';
					$db->FailTrans();
				}
			} else {
				$errors[] = 'Could not roll-up latest costs';
				$db->FailTrans();
			}
		}
		if (count($errors) == 0) {
			$flash->addMessage('Operation deleted');
			sendTo($this->name
					,'index'
					,$this->_modules
					,array('stitem_id' => $this->_data['stitem_id']));
		} else {
			$flash->addErrors($errors);
			sendBack();
		}
	}

	public function _new() {
		parent::_new();
		
		$mfoperation=$this->_uses[$this->modeltype];
		
		$stitem = new STItem();
		
		if ($mfoperation->isLoaded())
		{
			$this->_data['stitem_id'] = $mfoperation->stitem_id;
		}

		if (empty($this->_data['stitem_id']))
		{
			$stitems=$stitem->getAll();
			$this->view->set('stitems', $stitems);
			$stitem_id=key($stitems);
		}
		else
		{
			$stitem_id = $this->_data['stitem_id'];
		}
		
		$stitem->load($stitem_id);
		if (!empty($this->_data['stitem_id']))
		{
			$this->view->set('page_title', $this->getPageName('Operation for '.$stitem->getIdentifierValue()));
		}

		$this->getItemData($stitem_id);
		
		$this->view->set('no_ordering',true);
		
	}
		
	public function save() {

		$flash=Flash::Instance();
		
		if (!$this->checkParams('MFOperation')) {
			sendBack();
		}
		$data=$this->_data['MFOperation'];
		
		$db = DB::Instance();
		$db->StartTrans();
		$errors = array();
	
		if(!($data['volume_target']>0)){;
			$errors[]='Volume target must be a number greater than zero';
		}
		if(!($data['uptime_target']>0)){;
			$errors[]='Uptime target must be a number greater than zero';
		}
		if(!($data['quality_target']>0)){;
			$errors[]='Quality target must be a number greater than zero';
		}
		if(!($data['resource_qty']>0)){;
			$errors[]='Resource quantity must be a number greater than zero';
		}
		
		if (count($errors)==0 && parent::save_model('MFOperation')) {
			$stitem = new STItem;
			if ($stitem->load($this->saved_model->stitem_id)) {
				$old_costs = array(
					$stitem->latest_lab,
					$stitem->latest_ohd
				);
				$stitem->calcLatestCost();
				$new_costs = array(
					$stitem->latest_lab,
					$stitem->latest_ohd
				);
				$equal_costs = true;
				$total_costs = count($old_costs);
				for ($i = 0; $i < $total_costs; $i++) {
					if (bccomp($old_costs[$i], $new_costs[$i], $stitem->cost_decimals) != 0) {
						$equal_costs = false;
						break;
					}
				}
				if (!$equal_costs) {
					if (($stitem->saveCosts()) && (STCost::saveItemCost($stitem))) {
						if (!$stitem->rollUp(STItem::ROLL_UP_MAX_LEVEL)) {
							$errors[] = 'Could not roll-up latest costs';
						}
					} else {
						$errors[] = 'Could not save latest costs';
					}
				}
			} else {
				$errors[] = 'Could not save latest costs';
			}
		} else {
			$errors[] = 'Could not save operation';
		}
		if (count($errors)>0) {
			$db->FailTrans();
		}
		$db->CompleteTrans();
		if (count($errors) == 0) {
			sendTo($this->name
					,'index'
					,$this->_modules
					,array('stitem_id' => $data['stitem_id']));
		} else {
			$flash->addErrors($errors);
			$this->_data['stitem_id']= $data['stitem_id'];
			$this->refresh();
		}

	}
	
	public function view(){
		$id=$this->_data['id'];
		$object=&$this->_uses['MFOperation'];
		$object->load($id);
		$transaction= new MFOperation();
		$transaction->load($id);
		$this->view->set('transaction',$transaction);
		
		$sidebar = new SidebarController($this->view);
		$sidebar->addList(
			'Actions',
			array(
				'stores' => array('tag' => 'Show Item detail'
								 ,'link' => array('modules'=>$this->_modules
												 ,'controller'=>'STItems'
												 ,'action'=>'view'
												 ,'id'=>$transaction->stitem_id
												 )
								 ),
				'resources' => array('tag' => 'Show Resource detail'
									,'link' => array('modules'=>$this->_modules
													,'controller'=>'MFResources'
													,'action'=>'view'
													,'id'=>$transaction->mfresource_id
													)
									),
				'centres' => array('tag' => 'Show Centre detail'
								  ,'link' => array('modules'=>$this->_modules
												  ,'controller'=>'MFCentres'
												  ,'action'=>'view'
												  ,'id'=>$transaction->mfcentre_id
												  )
								  ),
				'new'=>array('tag'=>'New Operation'
							,'link'=>array('modules'=>$this->_modules
										  ,'controller'=>$this->name
										  ,'action'=>'new'
										  ,'stitem_id'=>$transaction->stitem_id
										  )
							),
				'edit'=>array('tag'=>'Edit Operation'
							 ,'link'=>array('modules'=>$this->_modules
										   ,'controller'=>$this->name
										   ,'action'=>'edit'
										   ,'id'=>$id
										   ,'stitem_id'=>$transaction->stitem_id
										   )
							 ),
				'delete'=>array('tag'=>'Delete Operation'
							   ,'link'=>array('modules'=>$this->_modules
											 ,'controller'=>$this->name
											 ,'action'=>'delete'
											 ,'id'=>$id
											 ,'stitem_id'=>$transaction->stitem_id
											 )
								)
				)
		);
		$this->view->register('sidebar',$sidebar);
		$this->view->set('sidebar',$sidebar);
		
	}

	public function showParts() {
		parent::index(new MFOperationCollection(new MFOperation));
	}

	/* Ajax functions
	 * 
	 * 
	 */
	public function getItemData($_stitem_id='')
	{
// Used by Ajax to get the From/To Locations/Bins based on Stock Item
		if(isset($this->_data['ajax'])) {
			if(!empty($this->_data['stitem_id'])) { $_stitem_id=$this->_data['stitem_id']; }
		} else {
// if this is Save and Add Another then need to get $_POST values to set context
			$_stitem_id=isset($_POST[$modeltype]['stitem_id'])?$_POST[$modeltype]['stitem_id']:$_stitem_id;
		}

		// store the ajax status in a different var, then unset the current one
		// we do this because we don't want the functions we all to get confused
		$ajax = isset($this->_data['ajax']);
		unset($this->_data['ajax']);

		$uom_list = $this->getUomList($_stitem_id);
		if ($ajax)
		{
			$output['uom_list']=array('data'=>$uom_list,'is_array'=>is_array($uom_list));
		}
		else
		{
			$this->view->set('uom_list',$uom_list);
		}
		
		$errors = array();
		$s_data = array('stitem_id' => $_stitem_id, 'start_date/end_date' => date(DATE_FORMAT));
		$this->search = structuresSearch::useDefault($s_data, $errors);
		if (count($errors) == 0) {
			self::showParts();
		}

		if ($ajax) {
			$html=$this->view->fetch($this->getTemplateName('show_parts'));
			$output['show_parts']=array('data'=>$html,'is_array'=>is_array($html));
		}
		
		
// ****************************************************************************
// Finally, if this is an ajax call, set the return data area
		if ($ajax) {
			$this->view->set('data',$output);
			$this->setTemplateName('ajax_multiple');
		}
	
	}
	
	/* Protected Functions
	 * 
	 */
	protected function getPageName($base=null,$action=null) {
		return parent::getPageName((empty($base)?'operations':$base), $action);
	}

}
?>
