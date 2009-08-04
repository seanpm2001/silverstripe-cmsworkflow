<?php

/**
 * ThreeStep, where an item is not actioned immediately.
 *
 * @package cmsworkflow
 * @subpackage threestep
 * @author Tom Rix
 */
class WorkflowThreeStepRequest extends WorkflowRequestDecorator {
	function approve($comment, $member = null, $notify = true) {
		if(!$member) $member = Member::currentUser();
		if(!$this->owner->Page()->canApprove($member)) {
			return false;
		}
	
		$this->owner->PublisherID = $member->ID;
		$this->owner->Status = 'Approved';
		$this->owner->write();

		$this->owner->addNewChange($comment, $this->owner->Status, $member);
		if($notify) $this->notifyApproved($comment);
		
		// The request is now approved, but we haven't published it yet
		// cause that's not how we roll here in ThreeStepRequest
		
		return true;
	}
	
	function publish($comment, $member = null, $notify = true) {
		if(!$member) $member = Member::currentUser();
		if(!$this->owner->Page()->canPublish($member)) {
			return false;
		}
		
		$this->owner->publish($comment, $member, $notify);
		
		if ($notify) {
			// Notify?
		}
		
		return true;
	}
	
	function saveAndPublish($comment, $member = null, $notify = true) {
		$this->approve($comment, $member, $notify);
		return $this->publish($comment, $member, $notify);
	}
	
	function notifyApproved($comment) {
		$author = $this->owner->Author();
		$subject = sprintf(
			_t("{$this->owner->class}.EMAIL_SUBJECT_APPROVED"),
			$this->owner->Page()->Title
		);
		
		$this->clearMembersEmailed();
		
		$publishers = $this->owner->Page()->PublisherMembers();
		foreach($publishers as $publisher){
			$this->addMemberEmailed($publisher);
			$this->owner->sendNotificationEmail(
				Member::currentUser(), // sender
				$publisher, // recipient
				_t("{$this->owner->class}.EMAIL_SUBJECT_APPROVED_FOR_PUBLISHING"),
				_t("{$this->owner->class}.EMAIL_PARA_APPROVED_FOR_PUBLISHING"),
				$comment,
				'WorkflowGenericEmail'
			);
		}

		$this->addMemberEmailed($author);
		$this->owner->sendNotificationEmail(
			Member::currentUser(), // sender
			$author, // recipient
			_t("{$this->owner->class}.EMAIL_SUBJECT_APPROVED_FOR_PUBLISHING"),
			_t("{$this->owner->class}.EMAIL_PARA_APPROVED_FOR_PUBLISHING"),
			$comment,
			'WorkflowGenericEmail'
		);
	}
	
	function notifyComment($comment) {
		// Comment recipients cover everyone except the person making the comment
		$commentRecipients = array();
		if(Member::currentUserID() != $this->owner->Author()->ID) $commentRecipients[] = $this->owner->Author();
		
		$receivers = $this->owner->Page()->ApproverMembers();
		foreach($receivers as $receiver){
			if(Member::currentUserID() != $receiver->ID) $commentRecipients[] = $receiver;
		}

		$this->clearMembersEmailed();
		foreach($commentRecipients as $recipient) {
			$this->addMemberEmailed($recipient);
			$this->owner->sendNotificationEmail(
				Member::currentUser(), // sender
				$recipient, // recipient
				_t("{$this->owner->owner->class}.EMAIL_SUBJECT_COMMENT"),
				_t("{$this->class}.EMAIL_PARA_COMMENT"),
				$comment,
				'WorkflowGenericEmail'
			);
		}
	}
	
	/**
	 * Notify any publishers assigned to this page when a new request
	 * is lodged.
	 */
	public function notifyAwaitingApproval($comment) {
		$publishers = $this->owner->Page()->ApproverMembers();
		$author = $this->owner->Author();

		$this->clearMembersEmailed();
		foreach($publishers as $publisher){
			$this->addMemberEmailed($publisher);
			$this->owner->sendNotificationEmail(
				$author, // sender
				$publisher, // recipient
				_t("{$this->class}.EMAIL_SUBJECT_AWAITINGAPPROVAL"),
				_t("{$this->class}.EMAIL_PARA_AWAITINGAPPROVAL"),
				$comment,
				'WorkflowGenericEmail'
			);
		}
	}
	
	/**
	 * Return the actions that can be performed on this workflow request.
	 * @return array The key is a LeftAndMainCMSWorkflow action, and the value is a label
	 * for the buton.
	 * @todo There's not a good separation between model and control in this stuff.
	 */
	function WorkflowActions() {
		$actions = array();
		
		if($this->owner->Status == 'Approved' && $this->owner->Page()->canPublish()) {
			$actions['cms_publish'] = _t("SiteTreeCMSWorkflow.WORKFLOWACTION_ACTION", "Publish change");
			return $actions;
		} elseif($this->owner->Status == 'AwaitingApproval' && $this->owner->Page()->canApprove()) {
			$actions['cms_approve'] = _t("SiteTreeCMSWorkflow.WORKFLOWACTION_APPROVE", "Approve");
			$actions['cms_requestedit'] = _t("SiteTreeCMSWorkflow.WORKFLOWACTION_REQUESTEDIT", "Request edit");
		} else if($this->owner->Status == 'AwaitingEdit' && $this->owner->Page()->canEdit()) {
			// @todo this couples this class to its subclasses. :-(
			$requestAction = (get_class($this) == 'WorkflowDeletionRequest') ? 'cms_requestdeletefromlive' : 'cms_requestpublication';
			$actions[$requestAction] = _t("SiteTreeCMSWorkflow.WORKFLOW_ACTION_RESUBMIT", "Re-submit");
		}
		
		$actions['cms_comment'] = _t("SiteTreeCMSWorkflow.WORKFLOW_ACTION_COMMENT", "Comment");
		$actions['cms_deny'] = _t("SiteTreeCMSWorkflow.WORKFLOW_ACTION_DENY","Deny/cancel");
		return $actions;
	}
	
	public static function get_by_approver($class, $approver, $status = null) {
		// To ensure 2.3 and 2.4 compatibility
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";

		if($status) $statusStr = "'".implode("','", $status)."'";

		$classes = (array)ClassInfo::subclassesFor($class);
		$classes[] = $class;
		$classesSQL = implode("','", $classes);
		
		// build filter
		$filter = "{$bt}WorkflowRequest_Approvers{$bt}.MemberID = {$approver->ID} 
			AND {$bt}WorkflowRequest{$bt}.ClassName IN ('$classesSQL')
		";
		if($status) {
			$filter .= "AND {$bt}WorkflowRequest{$bt}.Status IN (" . $statusStr . ")";
		} 
		
		return DataObject::get(
			"SiteTree", 
			$filter, 
			"{$bt}SiteTree{$bt}.{$bt}LastEdited{$bt} DESC",
			"LEFT JOIN {$bt}WorkflowRequest{$bt} ON {$bt}WorkflowRequest{$bt}.PageID = {$bt}SiteTree{$bt}.ID " .
			"LEFT JOIN {$bt}WorkflowRequest_Approvers{$bt} ON {$bt}WorkflowRequest{$bt}.ID = {$bt}WorkflowRequest_Approvers{$bt}.WorkflowRequestID"
		);
	}
	
	public static function get_by_publisher($class, $publisher, $status = null) {
		// To ensure 2.3 and 2.4 compatibility
		$bt = defined('Database::USE_ANSI_SQL') ? "\"" : "`";

		if($status) $statusStr = "'".implode("','", $status)."'";

		$classes = (array)ClassInfo::subclassesFor($class);
		$classes[] = $class;
		$classesSQL = implode("','", $classes);
		
		// build filter
		$filter = "{$bt}WorkflowRequest{$bt}.ClassName IN ('$classesSQL') ";
		if($status) {
			$filter .= "AND {$bt}WorkflowRequest{$bt}.Status IN (" . $statusStr . ")";
		} 
		
		$doSet = new DataObjectSet();
		$objects = DataObject::get(
			"SiteTree", 
			$filter, 
			"{$bt}SiteTree{$bt}.{$bt}LastEdited{$bt} DESC",
			"LEFT JOIN {$bt}WorkflowRequest{$bt} ON {$bt}WorkflowRequest{$bt}.PageID = {$bt}SiteTree{$bt}.ID "
		);
		
		if ($objects) {
			foreach($objects as $do) {
				if ($do->canPublish($publisher)) {
					$doSet->push($do);
				}
			}
		}
		
		return $doSet;
		return WorkflowRequest::get_by_publisher($class, $publisher, $status);
	}
	
	public static function get_by_author($class, $author, $status = null) {
		// $_REQUEST['showqueries'] = 1;
		return WorkflowRequest::get_by_author($class, $author, $status);
		// unset($_REQUEST['showqueries']);
	}
	
	public static function get($class, $status = null) {
		return WorkflowRequest::get($class, $status);
	}
}