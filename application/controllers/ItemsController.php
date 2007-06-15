<?php

require_once MODEL_DIR.DIRECTORY_SEPARATOR.'Item.php';
/**
 * @package Omeka
 **/
require_once 'Kea/Controller/Action.php';
class ItemsController extends Kea_Controller_Action
{		
	public function init() 
	{
		$this->_table = $this->getTable('Item');
		$this->_modelClass = 'Item';
	}
	
	public function addAction()
	{
		$item = new Item;
		$user = Kea::loggedIn();
		$item->User = $user;
		if($this->commitForm($item)) {
			$this->pluginHook('onAddItem', array($item));
			return $this->_redirect('items/browse');
		}else {
			return $this->render('items/add.php',compact('item'));
		}
	}
	
	public function tagsAction()
	{
		$this->_forward('Tags', 'browse', array('tagType' => 'Item', 'renderPage'=>'items/tags.php'));
	}
	
	protected function search( $select, $terms)
	{
		$conn = $this->getConn();
		$conn->execute("CREATE TEMPORARY TABLE temp_search (id BIGINT AUTO_INCREMENT, item_id BIGINT UNIQUE, PRIMARY KEY(id))");
		
		$itemSelect = clone $select;
		
		//Search the items table	
		$itemsClause = "i.title, i.publisher, i.language, i.relation, i.spatial_coverage, i.rights, i.description, i.source, i.subject, i.creator, i.additional_creator, i.contributor, i.rights_holder, i.provenance, i.citation";
		
		$itemSelect->where("MATCH ($itemsClause) AGAINST (? WITH QUERY EXPANSION)", $terms);
				
		//Grab those results, place in the temp table		
		$insert = "INSERT INTO temp_search (item_id) ".$itemSelect->__toString();
		$conn->execute($insert);
		
		
		//Search the metatext table
		$mSelect = clone $select;
		$metatextClause = "m.text";
		$mSelect->joinInner("metatext m", "m.item_id = i.id");
		$mSelect->where("MATCH ($metatextClause) AGAINST (? WITH QUERY EXPANSION)", $terms);
	//	echo $mSelect;
		
		//Put those results in the temp table
		$insert = "REPLACE INTO temp_search (item_id) ".$mSelect;
		$conn->execute($insert);
		
	//	Zend::dump( $conn->execute("SELECT * FROM temp_search")->fetchAll() );exit;
		
		$select->joinInner('temp_search ts', 'ts.item_id = i.id');
		$select->order('ts.id ASC');
	}
	
	/**
	 * New Strategy: this will run a SQL query that selects the IDs, then use that to hydrate the Doctrine objects.
	 * Stupid Doctrine.  Maybe their new version will be better.
	 *
	 * @return mixed|void
	 **/
	public function browseAction()
	{			
		require_once 'Kea/Select.php';
		$select = new Kea_Select($this->getConn());
	
		$select->from('items i','i.id');

		//Run the permissions check
		if( !$this->isAllowed('showNotPublic') or $this->_getParam('public') ) {
			$select->where('i.public = 1');
		} 
		
		
		//Grab the total number of items in the table(as differentiated from the result count)
		//Make sure that the query that retrieves the total number of Items also contains the permissions check
		$countQuery = clone $select;
		$countQuery->resetFrom('items i', 'COUNT(*)');
		$total_items = $countQuery->fetchOne();
		if(!$total_items) $total_items = 0;
		
		Zend::register('total_items', $total_items);
		
		//filter items based on featured (only value of 'true' will return featured items)
		if($featured = $this->_getParam('featured')) {
			$select->where('i.featured = '.($featured == 'true' ? '1':'0'));
		}
		
		//filter based on collection
		if($collection = $this->_getParam('collection')) {
			
			$select->joinInner('collections c', 'i.collection_id = c.id');
			
			if(is_numeric($collection)) {
				$select->where('c.id = ?', $collection);
			}else {
				$select->where('c.name = ?', $collection);
			}
		}
		
		//filter based on type
		if($type = $this->_getParam('type')) {
			
			$select->joinInner('types ty','i.type_id = ty.id');
			if(is_numeric($type)) {
				$select->where('ty.id = ?', $type);
			}else {
				$select->where('ty.name = ?', $type);
			}
		}
		
		//filter based on tags
		if( ($tag = $this->_getParam('tag')) || ($tag = $this->_getParam('tags')) ) {
			
			$select->joinInner('items_tags it','it.item_id = i.id');
			$select->joinInner('tags t', 'it.tag_id = t.id');
			if(!is_array($tag) )
			{
				$tag = explode(',', $tag);
			}
			foreach ($tag as $key => $t) {
				$select->where('t.name = ?', $t);
			}			
		}
		
		//exclude Items with given tags
		if(($excludeTags = $this->_getParam('withoutTags'))) {
				if(!is_array($excludeTags))
				{
					$excludeTags = explode(',', $excludeTags);
				}
				$subSelect = new Kea_Select($this->getConn());
				$subSelect->from('items i INNER JOIN items_tags it ON it.item_id = i.id 
							INNER JOIN tags t ON it.tag_id = t.id', 'i.id');
								
				foreach ($excludeTags as $key => $tag) {
					$subSelect->where("t.name LIKE ?", $tag);
				}	
		
				$select->where('i.id NOT IN ('.$subSelect->__toString().')');
		}
		
/*
		if(($from_record = $this->_getParam('relatedTo')) && @$from_record->exists()) {
			$componentName = $from_record->getTable()->getComponentName();
			$alias = $this->_table->getAlias($componentName);
			$query->innerJoin("Item.$alias rec");
			$query->addWhere('rec.id = ?', array($from_record->id));
		}
*/

		//Check for a search
		if($search = $this->_getParam('search')) {
			$this->search($select, $search);
		}
		
		//Before the pagination, please grab the number of results that this full query will return
		$resultCount = clone $select;
		$resultCount->resetFrom('items i','COUNT(*)');
		$resultCount->unsetOrderBy();
		$total_results = $resultCount->fetchOne();
		
		/** 
		 * Now process the pagination
		 * 
		 **/
		$paginationUrl = $this->getRequest()->getBaseUrl().'/items/browse/';
		$options = array(	'num_links'=>	5, 
							'per_page'=>	10,
							'page'		=> 	1,
							'pagination_url' => $paginationUrl);
							
		//check to see if these options were changed by request vars
		$reqOptions = $this->_getAllParams();
		
		$options = array_merge($options, $reqOptions);
		
		$select->limitPage($options['page'], $options['per_page']);


				
		$res = $select->fetchAll();
		
		//Drop the search table if it exists
		$this->getConn()->execute("DROP TABLE IF EXISTS temp_search");
				
		foreach ($res as $key => $value) {
			$ids[] =  $value['id'];
		}		
		
		//Serve up the pagination
		require_once 'Kea/View/Functions.php';
		$pagination = pagination($options['page'], $options['per_page'], $total_results, $options['num_links'], $options['pagination_url']);
		Zend::register('pagination', $pagination);			

		//Finally, hydrate the Doctrine objects with the array of ids given
		$query = new Doctrine_Query;
		$query->select('i.*, t.*')->from('Item i');
		$query->leftJoin('Item.Tags t');
		$query->leftJoin('Item.Collection c');
		$query->leftJoin('i.Type ty');
		
		//If no IDs were returned in the first query, then whatever
		if(!empty($ids)) {
			$where = "(i.id = ".join(" OR i.id = ", $ids) . ")";
		}else {
			$where = "1 = 0";
		}
		
		
		$query->where($where);

		//Order by recent-ness
		if($recent = $this->_getParam('recent')) {
			$query->addOrderBy('i.added DESC');
		}
		
		$items = $query->execute();
		
		Zend::register('total_results', $total_results);
		
		$this->pluginHook('onBrowseItems', array($items));
		
		return $this->render('items/browse.php', compact('total_items', 'items'));
	}
	
	/**
	 * Processes and saves the form to the given record
	 *
	 * @param Kea_Record
	 * @return boolean True on success, false otherwise
	 **/
	protected function commitForm($item)
	{
		if(!empty($_POST))
		{
			
			$conn = $this->getConn();
			$conn->beginTransaction();
			
			$clean = $_POST;
			unset($clean['id']);
			
			//If item is being made public
			if(!$item->public && $clean['public'] == 1) {
				$wasMadePublic = true;
			}
			
			$validDate = $item->processDate('date',
								$clean['date_year'],
								$clean['date_month'],
								$clean['date_day']);
								
			$validCoverageStart = $item->processDate('temporal_coverage_start', 
								$clean['coverage_start_year'],
								$clean['coverage_start_month'],
								$clean['coverage_start_day']);
								
			$validCoverageEnd = $item->processDate('temporal_coverage_end', 
								$clean['coverage_end_year'],
								$clean['coverage_end_month'],
								$clean['coverage_end_day']);	
						
			
			
			//Special method for untagging other users' tags
			if($this->isAllowed('untagOthers')) {
				$tagsDeleted = $this->removeTag($item);
			}
			
			//Mirror the form to the record
			$item->setFromForm($clean);
			
			//Check to see if the date was valid
			if(!$validDate) {
				$this->flash('The date provided is invalid.  Please provide a correct date.');
				return false;
			}
			
			//If someone is providing coverage dates, they need to provide both a start and end or neither
			if( (!$validCoverageStart and $validCoverageEnd) or ($validCoverageStart and !$validCoverageEnd) ) {
				
				$this->flash('For coverage, both start date and end date must be specified, otherwise neither may be specified.');
				return false;
			}
			
			if(!empty($clean['change_type'])) return false;
			if(!empty($clean['add_more_files'])) return false;
			
			if(!empty($_FILES["file"]['name'][0])) {
				//Handle the file uploads
				foreach( $_FILES['file']['error'] as $key => $error )
				{ 
					try{
						$file = new File();
						$file->upload('file', $key);
						$item->Files->add($file);
					}catch(Exception $e) {
						$this->flash($e->getMessage());
						$file->delete();
						$conn->rollback();
						return false;
					}
				
				}
			}
			
			/* Delete files what that have been chosen as such */
			if($filesToDelete = $clean['delete_files']) {
				$conn = $this->getConn();
				foreach ($item->Files as $key=> $file) {
					if(in_array($file->id,$filesToDelete)) {
						$file->delete();
					}
				}
			}		
						
			//Handle the boolean vars
			if(array_key_exists('public', $clean)) {
				$item->public = (bool) $clean['public'];
			}
			
			if(array_key_exists('featured', $clean)) {
				$item->featured = (bool) $clean['featured'];
			}
			
			try {
				$item->save();
				
				//Tagging must take place after the Item has been saved (b/c otherwise no Item ID is set)
				if(array_key_exists('modify_tags', $clean) || !empty($clean['tags'])) {
					$user = Kea::loggedIn();
					$item->applyTagString($clean['tags'], $user->id);
				}
				
				//If the item was made public, fire the plugin hook
				if($wasMadePublic) {
					$this->pluginHook('onMakePublicItem', array($item));
				}
				
				$conn->commit();
				return true;
			}
			catch(Doctrine_Validator_Exception $e) {
				$item->gatherErrors($e);
				$conn->rollback();
				
				//Reload the files b/c of a stupid bug
				foreach ($item->Files as $key => $file) {
					if(!$file->exists()) {
						$file->delete();
					}
					unset($item->Files[$key]);
				}
				
				return false;
			}catch(Exception $e) {
				$this->flash($e->getMessage());
			}	
		}
		return false;
	}
	
	/**
	 * Get all the collections and all the active plugins for the form
	 *
	 * @return void
	 **/
	protected function loadFormData() 
	{
		$collections = $this->getTable('Collection')->findAll();
		$plugins = $this->getTable('Plugin')->findActive();
		$types = $this->getTable('Type')->findAll();
		
		$this->_view->assign(compact('collections', 'plugins', 'types'));
	}
	
	public function showAction() 
	{
		$item = $this->findById();
		
		//If the item is not public, check for permissions
		if(!$item->public && !$this->isAllowed('showNotPublic')) {
			$this->_redirect('403');
		}
		
		//Add the tags
		 
		if(array_key_exists('modify_tags', $_POST) || !empty($_POST['tags'])) {
			
		 	if($this->isAllowed('tag')) {
				$tagsAdded = $this->commitForm($item);
				$item = $this->findById();
			}else {
				$this->flash('User does not have permission to add tags.');
			}
		}
			
		$user = Kea::loggedIn();

		//@todo Does makeFavorite require a permissions check?
		if($this->getRequest()->getParam('makeFavorite')) {
			$this->makeFavorite($item,$user);
		}
		
		
		
		if($tagsAdded || $tagsDeleted) {
			//This is a workaround for the fact that the Tags collection doesn't get automatically refreshed
			$item->Tags = $this->getTable('Tag')->findSome(array('item_id'=>$item->id));
		}
		
		$item->refresh();
		
		Zend::Register('item', $item);
		
		$this->pluginHook('onShowItem', array($item));
		
		return $this->render('items/show.php', compact("item", 'user'));
	}
	
	protected function makeFavorite($item, $user)
	{
		if($item->isFavoriteOf($user)) {
				//Make un-favorite
				$if = $this->getTable('ItemsFavorites')->findBySql("user_id = {$user->id} AND item_id = {$item->id}");
				$if->delete();
		} else {
			//Make it favorite
			$if = new ItemsFavorites();
			$if->Item = $item;
			$if->User = $user;
			$if->save();
			$this->pluginHook('onMakeFavoriteItem', array($item, $user));
		}
	}
	
	/**
	 * Will remove all instances of a particular tag from a particular Item
	 * Checks for $_POST key with name = 'remove_tag' and value = tag ID
	 *
	 * @return bool
	 **/
	protected function removeTag($item)
	{
		if(array_key_exists('remove_tag', $_POST)) {
			$tagId = $_POST['remove_tag'];
			$tagToDelete = $this->getTable('Tag')->find($tagId);
			if($tagToDelete) {
				$this->pluginHook('onUntagItem', array($item, $tagToDelete->name, $this->_user));
				
				//delete the tag from the Item
				return $item->deleteTag($tagToDelete, null, true);
			}
		}
	}
}
?>