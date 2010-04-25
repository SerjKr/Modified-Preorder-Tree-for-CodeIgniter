<?php if(!defined('BASEPATH')) exit('No direct script access allowed');
class Node
{
	/* Methods:

	 *	get_tree($table, $lft = 1, $rght = null)

	 *	get_node_kin($id, $table)//retrieve a node with it's kin

	 *	add_node($data, $table, $target_pos, $target_id)

	 *	delete_node($id, $table, $recursive = TRUE)
	 
	 *  delete_shift_node($id, $table)

	 *	update_node($id, $table, $target_pos, $target_id, $data)

	 *	rebuild_tree($table, $parent_id=null, $left=1, $i=-1)		

	 */
	
	// inialize the CI instance, so we can use the database functionality
	// since it is otherwise not accessibly from within this class
	// note: to call the database class from here use, $this->CI->db
	private $CI;
	function Node()
	{
		$this->CI =& get_instance();
	}
	
	function get_tree($table, $lft = 1, $rght = null)
	{
		//if no right parameter is given
		//get max right
		if($rght === null)
		{
			$sql = "SELECT rght from `".$table."`".
					"ORDER BY rght DESC ".
					"LIMIT 1";
			$query = $this->CI->db->query($sql);
			$rght = $query->row()->rght;
			$query->free_result();
		}

		//get tree between given lft and rght values
		$sql = "SELECT * FROM `".$table."` ".
				"WHERE lft BETWEEN ".$lft." AND ".$rght." ".
				"ORDER BY lft ASC;";
		$query = $this->CI->db->query($sql);
		$tree = $query->result();
		//return the tree
		return $tree;
	}

	function get_node_kin($id, $table)//retrieve a node with it's kin
	{	
		//check if there is a sibling to the left
		//if not then nearest node to left is its parent

		//retrieve node
		$this->CI->db->where('id', $id);
		$query = $this->CI->db->get($table);
		$node = $query->row();

		//retrieve parent
		$sql = "SELECT * FROM `".$table."` WHERE lft < ".$node->lft." AND rght > ".$node->rght." ORDER BY lft DESC LIMIT 1;";
		$query = $this->CI->db->query($sql);
		$parent = $query->row();
		$query->free_result();

		//retrieve 'naaste' aka kin
		$this->CI->db->where('rght', $node->lft - 1);
		$kin_query = $this->CI->db->get($table);
		$num_rows = $kin_query->num_rows();
		if($num_rows > 0)
		{
			$node->target = "after";
			$kin = $kin_query->row();
			$kin_query->free_result();	
		}
		else
		{
			$node->target = "under";
			$kin = $parent;
		}
		$node->kin = $kin->id;

		return $node;
	}


	function add_node($data, $table, $target_pos, $target_id)
	{
		/* data : data for the node to be added
		 * table : table in which to insert node
		 * target_pos : where to insert new node in relation to target node
		 * target_id : id of target node
		 */

		//get target information
		$this->CI->db->where('id', $target_id);
		$query = $this->CI->db->get($table);
		$target = $query->row();
		$query->free_result();

		$query = FALSE;
		//reorder nodes
		switch($target_pos)
		{
			case "under" :
				$op = '>='; // greater than or equal to
				$query = TRUE; //no need to update lft values
				$data['lft'] = $target->rght;
				$data['rght'] = $data['lft'] + 1;
				$data['level'] = $target->level + 1;
				$data['parent'] = $target->id;
				break;
			case "after" :
				$op = '>'; //greater than
				$sql = "UPDATE `".$table."` SET lft=lft+2 WHERE lft ".$op." " .$target->rght .";";
				$query = $this->CI->db->query($sql);
				$data['lft'] = $target->rght + 1;
				$data['rght'] = $data['lft'] + 1;
				$data['level'] = $target->level;
				$data['parent'] = $target->parent;
				break;
			case "before" :
				$op = '>='; //greater than or equal to
				$sql = "UPDATE `".$table."` SET lft=lft+2 WHERE lft ".$op." " .$target->lft .";";
				$query = $this->CI->db->query($sql);
				$data['lft'] = $target->lft;
				$data['rght'] = $data['lft'] + 1;
				$data['level'] = $target->level;
				$data['parent'] = $target->parent;
				break;
		}

		if($query === TRUE)//if left values changed successfully (if needed)
		{
			$sql = "UPDATE `".$table."` SET rght=rght+2 WHERE rght ".$op." " .$target->rght .";";
			$query = $this->CI->db->query($sql);
		}

		if($query === TRUE)//if right values changed successfully
		{
			//add item
			$this->CI->db->insert($table, $data);
			return TRUE;
		}
	}


	function delete_node($id, $table, $recursive = TRUE)
	{
		/* This function deletes an item and its children
		therefore it needs to fetch it's descendants,
		but will get all descendants the first time around. Therefore
		it will only need to 'recurse' one time */

		//get item info before deletion
		$this->CI->db->where('id', $id);
		$query = $this->CI->db->get($table);
		$node = $query->row();
		$query->free_result();

		//check how many descendants the item has
		$num_descendants =	($node->rght - $node->lft - 1) / 2;
		if($num_descendants !== 0 && $recursive === TRUE)
		{
			//get descendants
			$descendants = $this->get_tree($table, $node->lft, $node->rght);
			//start with the lowest child first
			$descendants = array_reverse($descendants);

			//delete all desendants (starting at the bottom) without recursing again
			foreach($descendants as $descendant)
			{
				//delete descendant
				$this->delete_node($descendant->id, $table, FALSE);
			}
		}

		//update left and right values of non-deleted items
		$sql_lft = "UPDATE `".$table."` SET lft=lft-2 WHERE lft > " .$node->rght .";";
		$sql_rght = "UPDATE `".$table."` SET rght=rght-2 WHERE rght > " .$node->rght .";";

		$query_lft = $this->CI->db->query($sql_lft);
		$query_rght = $this->CI->db->query($sql_rght);

		//if these queries are successfull, delete the item and return TRUE
		if($query_lft === TRUE && $query_rght === TRUE)
		{
			//delete item		
			$this->CI->db->where('id', $id);
			$this->CI->db->delete($table);
			return TRUE;
		}
	}
	
	function delete_shift_node($id, $table)
	{
		/* This function deletes a node, and shifts it's children up */
	
		//get item info before deletion
		$this->CI->db->where('id', $id);
		$query = $this->CI->db->get($table);
		$node = $query->row();
		$query->free_result();
		
		//check how many descendants the item has
		$num_descendants =	($node->rght - $node->lft - 1) / 2;
		if($num_descendants > 0)
		{					
			//assign direct children their new parent
			$lvl = $node->level + 1;
			$sql = "UPDATE `".$table."` SET parent=".$node->parent." ".
					"WHERE parent=".$node->id.";";
			$this->CI->db->query($sql);
			
			//shift all descendants up
			$sql = "UPDATE `".$table."` SET `level`=`level`-1 ".
					"WHERE lft BETWEEN ".$node->lft." AND ".$node->rght.";";
			$this->CI->db->query($sql);
			
			//adjust all descendants left values
			$sql = "UPDATE `".$table."` SET lft=lft-1 ".
					"WHERE lft BETWEEN ".$node->lft." AND ".$node->rght.";";
			$this->CI->db->query($sql);
			
			//adjust all descendants right values
			$sql = "UPDATE `".$table."` SET rght=rght-1 ".
					"WHERE lft BETWEEN ".$node->lft." AND ".$node->rght.";";
			$this->CI->db->query($sql);		
		}

		//update left and right values of non-deleted items
		$sql_lft = "UPDATE `".$table."` SET lft=lft-2 WHERE lft > " .$node->rght .";";
		$sql_rght = "UPDATE `".$table."` SET rght=rght-2 WHERE rght > " .$node->rght .";";

		$query_lft = $this->CI->db->query($sql_lft);
		$query_rght = $this->CI->db->query($sql_rght);

		//if these queries are successfull, delete the item and return TRUE
		if($query_lft === TRUE && $query_rght === TRUE)
		{
			//delete item		
			$this->CI->db->where('id', $id);
			$this->CI->db->delete($table);
			return TRUE;
		}		
	}

	function update_node($id, $table, $target_pos, $target_id, $data)
	{
		/* id : id of node to update
		 * table : name of the table in which the node resides
		 * target_pos : where it is placed in relation to the target node
		 * target_id : id of the target node
		 * data : the data with which to update the node
		 * current_state : the current information of the item in object format
		 */

		$rebuild = FALSE;
		if($target_pos !== 'unchanged' && $target_id !== 'unchanged' )
		{
			switch($target_pos)
			{
				case "under" :
					$data['parent'] = $target_id;
					break;
				case "before" :
					//on rebuild tree is built in order of lft values
					//to put the item before we make it's lft value less than the target
					//the target will have its lft+1
					//the updated node will have the targets old lft value
					//possible overlap so far harmless
					
					//get target
					$this->CI->db->where('id', $target_id);
					$query = $this->CI->db->get($table);
					$target = $query->row();
					$query->free_result();
					
					//change lft values of target
					$sql = "UPDATE `".$table."` SET lft=lft+1 ".
							"WHERE `id` = ".$target_id.";";
					$this->CI->db->query($sql);
					
					//change lft values of to be updated item
					$sql = "UPDATE `".$table."` SET lft = '".$target->lft."' ".
							"WHERE `id`=".$id.";";
					$this->CI->db->query($sql);
					$data['parent'] = $target->parent;
					break;
				case "after" :
					//get target
					$this->CI->db->where('id', $target_id);
					$query = $this->CI->db->get($table);
					$target = $query->row();
					$query->free_result();

					//on rebuild tree is built in order of lft values
					//to put the item after we make it's lft value +1 of the target
					//possible overlap so far harmless
					$lft = $target->lft + 1;
					$sql = "UPDATE `".$table."` SET lft = '".$lft."' ".
							"WHERE `id`=".$id.";";
					$this->CI->db->query($sql);
					
					$data['parent'] = $target->parent;
					$query->free_result();
					break;
			}
			$rebuild = TRUE;
		}

		//update item
		$this->CI->db->where('id', $id);
		$this->CI->db->update($table, $data);

		//rebuild if necessary
		if($rebuild === TRUE) $this->rebuild_tree($table);
	}

	function rebuild_tree($table, $parent_id=null, $left=1, $i=-1)
	{
		//$i defaults to root, which is at level -1
		//if parent is not given retrieve root
		if($parent_id === null)
		{
			$this->CI->db->where('title', 'root');
		}
		//else use parent id given
		else
		{
			$this->CI->db->where('id', $parent_id);
		}
		//get parent
		$query = $this->CI->db->get($table);
		$parent = $query->row();
		$query->free_result();

		// the right value of this node is the left value + 1  
		$right = $left+1;  

		// get all children of this node  	
		$sql = "SELECT * FROM `".$table."` WHERE parent=".$parent->id." ORDER BY lft ASC";
		$query = $this->CI->db->query($sql);
		$result = $query->result();
		$query->free_result();

	   foreach ($result as $row)
	   {  
			// recursive execution of this function for each  
			// child of this node  
			// $right is the current right value, which is  
			// incremented by the rebuild_tree function  
			$right = $this->rebuild_tree($table, $row->id, $right, $i+1);  
		}  

		// we've got the left value, and now that we've processed  
		// the children of this node we also know the right value  
		$sql = "UPDATE `".$table."` SET lft=".$left.", rght=".$right.", `level` =".$i." ".
				"WHERE id=".$parent->id.";";
		$this->CI->db->query($sql);  

	   // return the right value of this node + 1  
		return $right+1;  
	}
}
