<?php
//turn access
$this->ACL->turn(array($this->module, 'add_comments'));

$id = (int)$id;



if ($id < 1) {
	$html = '';
} else { 

	$markers = array();
	$name = (!empty($_SESSION['user']['name'])) ? h($_SESSION['user']['name']) : '';
	$message = '';	
	$info = '';
	$replyId = (!empty($_GET['reply'])) ? (int)$_GET['reply'] : 0;
	

	/* if an error */
	if (isset($_SESSION['FpsForm'])) {
        $markers['errors'] = $this->render('infomessage.html', array('info_message' => $_SESSION['FpsForm']['errors']));
		$name = h($_SESSION['FpsForm']['name']);
		$message = h($_SESSION['FpsForm']['message']);
		if (isset($_SESSION['FpsForm']['parent_id'])) {
			$replyId = (int)$_SESSION['FpsForm']['parent_id'];
		}
		unset($_SESSION['FpsForm']);
	}

	
	$replyComment = null;
	if ($replyId > 0) {
		$commentsModel = $this->Register['ModManager']->getModelInstance('Comments');
		if ($commentsModel) {
			$replyComment = $commentsModel->getById($replyId);
			if (!$replyComment
				|| (int)$replyComment->getEntity_id() !== $id
				|| $replyComment->getModule() !== $this->module
				|| $replyComment->getPremoder() === 'rejected') {
				$replyComment = null;
				$replyId = 0;
			}
		}
	}
	
	$markers['parent_id'] = $replyId;
	$markers['reply_notice'] = '';
	if ($replyComment) {
		$cancelUrl = get_url('/' . $this->module . '/view/' . $id) . '#sendForm';
		$markers['reply_notice'] = '<div class="comment-reply-notice">'
			. sprintf(__('Reply to %s'), '<strong>' . h($replyComment->getName()) . '</strong>')
			. ' <a href="' . h($cancelUrl) . '" class="comment-reply-cancel">' . __('Cancel reply') . '</a></div>';
	}


	$markers['action'] = get_url('/' . $this->module . '/add_comment/' . $id);
	if (!$this->ACL->turn(array('other', 'no_captcha'), false)) {
		list ($captcha, $captcha_text) = $this->Register['Protector']->getCaptcha('addcomment');
		$markers['add_comment_captcha'] = $captcha;
		$markers['add_comment_captcha_text'] = $captcha_text;
	}
	
	$markers['disabled'] = (!empty($_SESSION['user'])) ? ' disabled="disabled"' : '';
	$markers['add_comment_name'] = $name;
	$markers['add_comment_message'] = $message;
	$html = $this->render('addcommentform.html', array('data' => $markers));
}
