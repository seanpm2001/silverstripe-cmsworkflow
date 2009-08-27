<?php

class BatchPublishPages extends CMSBatchAction {
	function getActionTitle() {
		return _t('BatchPublishPages.PUBLISH_PAGES', 'CMS Workflow Publish pages');
	}
	function getDoingText() {
		return _t('BatchPublishPages.PUBLISHING_PAGES', 'Publishing pages');
	}

	function run(DataObjectSet $pages) {
		return $this->batchaction($pages, 'batchPublish',
			_t('BatchPublishPages.PUBLISHED_PAGES', 'Published %d pages')
		);
	}
}

