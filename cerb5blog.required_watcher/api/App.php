<?php
class Cerb5blogRequiredWatchersEventListener extends DevblocksEventListenerExtension {
	function __construct($manifest) {
		parent::__construct($manifest);
	}

	/**
	 * @param Model_DevblocksEvent $event
	 */
	function handleEvent(Model_DevblocksEvent $event) {
		switch($event->id) {
			case 'ticket.property.pre_change':
				$this->_workerAssigned($event);
				break;
			case 'ticket.comment.create':
				$this->_newTicketComment($event);
				break;
			case 'ticket.reply.inbound':
				$this->_sendForwards($event, true);
				break;
			case 'ticket.reply.outbound':
				$this->_sendForwards($event, false);
				break;
		}
	}

	private function _newTicketComment($event) {
		@$comment_id = $event->params['comment_id'];
		@$ticket_id = $event->params['ticket_id'];
		@$address_id = $event->params['address_id'];
		@$comment = $event->params['comment'];
    	
		if(empty($ticket_id) || empty($address_id) || empty($comment))
			return;
    		
		// Resolve the address ID
		if(null == ($address = DAO_Address::get($address_id)))
			return;
			
		// Try to associate the author with a worker
		if(null == ($worker_addy = DAO_AddressToWorker::getByAddress($address->email)))
			return;
				
		if(null == ($worker = DAO_Worker::getAgent($worker_addy->worker_id)))
			return;
			
		$url_writer = DevblocksPlatform::getUrlService();

		$mail_service = DevblocksPlatform::getMailService();
		$mailer = null; // lazy load
    		
		$settings = DevblocksPlatform::getPluginSettingsService();
		@$default_from = $settings->get('cerberusweb.core',CerberusSettings::DEFAULT_REPLY_FROM,CerberusSettingsDefaults::DEFAULT_REPLY_FROM);
		@$default_personal = DevblocksPlatform::importGPC($_POST['default_reply_personal'],'string',$settings->get('cerberusweb.core',CerberusSettings::DEFAULT_REPLY_PERSONAL,CerberusSettingsDefaults::DEFAULT_REPLY_PERSONAL));

		if(null == ($ticket = DAO_Ticket::getTicket($ticket_id)))
			return;

		// (Action) Forward E-mail:
		
		// Sanitize and combine all the destination addresses
		$next_worker = DAO_Worker::getAgent($ticket->next_worker_id);
		$notify_emails = $next_worker->email;
		
		if(empty($notify_emails))
			return;
			
		if(null == (@$last_message = end($ticket->getMessages()))) { /* @var $last_message CerberusMessage */
			continue;
		}
		
		if(null == (@$last_headers = $last_message->getHeaders()))
			continue;
			
		$reply_to = $default_from;
		$reply_personal = $default_personal;
			
		// See if we need a group-specific reply-to
		if(!empty($ticket->team_id)) {
			@$group_from = DAO_GroupSettings::get($ticket->team_id, DAO_GroupSettings::SETTING_REPLY_FROM);
			if(!empty($group_from))
				$reply_to = $group_from;
				
			@$group_personal = DAO_GroupSettings::get($ticket->team_id, DAO_GroupSettings::SETTING_REPLY_PERSONAL);
			if(!empty($group_personal))
				$reply_personal = $group_personal;
		}
		
		try {
			if(null == $mailer)
				$mailer = $mail_service->getMailer(CerberusMail::getMailerDefaults());
				
			// Create the message
			
			$mail = $mail_service->createMessage();
			$mail->setTo(array($notify_emails));
			$mail->setFrom(array($reply_to => $reply_personal));
			$mail->setReplyTo($reply_to);
			$mail->setSubject(sprintf("[RW: comment #%s]: %s [comment]",
				$ticket->mask,
				$ticket->subject
			));
			
			$headers = $mail->getHeaders();
			
			if(false !== (@$in_reply_to = $last_headers['in-reply-to'])) {
				$headers->addTextHeader('References', $in_reply_to);
				$headers->addTextHeader('In-Reply-To', $in_reply_to);
			}
				
			// Build the body
			$comment_text = sprintf("%s (%s) comments:\r\n%s\r\n",
				$worker->getName(),
				$address->email,
				$comment
			);
				
			$headers->addTextHeader('X-Mailer','Cerberus Helpdesk (Build '.APP_BUILD.')');
			$headers->addTextHeader('Precedence','List');
			$headers->addTextHeader('Auto-Submitted','auto-generated');
				
			$mail->setBody($comment_text);
			
			$result = $mailer->send($mail);
				
		} catch(Exception $e) {
			if(!empty($message_id)) {
				$fields = array(
					DAO_MessageNote::MESSAGE_ID => $message_id,
					DAO_MessageNote::CREATED => time(),
					DAO_MessageNote::WORKER_ID => 0,
					DAO_MessageNote::CONTENT => 'Exception thrown while sending watcher email: ' . $e->getMessage(),
					DAO_MessageNote::TYPE => Model_MessageNote::TYPE_ERROR,
				);
				DAO_MessageNote::create($fields);
			}
		}
	}

	private function _workerAssigned($event) {
		@$ticket_ids = $event->params['ticket_ids'];
		@$changed_fields = $event->params['changed_fields'];
    	
		if(empty($ticket_ids) || empty($changed_fields))
			return;

		@$next_worker_id = $changed_fields[DAO_Ticket::NEXT_WORKER_ID];

		// Make sure a next worker was assigned
		if(empty($next_worker_id))
			return;

		$url_writer = DevblocksPlatform::getUrlService();
    	
		$mail_service = DevblocksPlatform::getMailService();
		$mailer = null; // lazy load
    		
		$settings = DevblocksPlatform::getPluginSettingsService();
		@$default_from = $settings->get('cerberusweb.core',CerberusSettings::DEFAULT_REPLY_FROM,CerberusSettingsDefaults::DEFAULT_REPLY_FROM);
		@$default_personal = DevblocksPlatform::importGPC($_POST['default_reply_personal'],'string',$settings->get('cerberusweb.core',CerberusSettings::DEFAULT_REPLY_PERSONAL,CerberusSettingsDefaults::DEFAULT_REPLY_PERSONAL));

		// Loop through all assigned tickets
		$tickets = DAO_Ticket::getTickets($ticket_ids);
		foreach($tickets as $ticket) { /* @var $ticket CerberusTicket */
			// If the next worker value didn't change, skip
			if($ticket->next_worker_id == $next_worker_id)
				continue;
			
			// (Action) Forward Email To:

			// Sanitize and combine all the destination addresses
			$next_worker = DAO_Worker::getAgent($next_worker_id);
			$notify_emails = $next_worker->email;
			
			if(empty($notify_emails))
				return;
				
			if(null == (@$last_message = end($ticket->getMessages()))) { /* @var $last_message CerberusMessage */
				continue;
			}
			
			if(null == (@$last_headers = $last_message->getHeaders()))
				continue;
				
			$reply_to = $default_from;
			$reply_personal = $default_personal;
				
			// See if we need a group-specific reply-to
			if(!empty($ticket->team_id)) {
				@$group_from = DAO_GroupSettings::get($ticket->team_id, DAO_GroupSettings::SETTING_REPLY_FROM);
				if(!empty($group_from))
					$reply_to = $group_from;
					
				@$group_personal = DAO_GroupSettings::get($ticket->team_id, DAO_GroupSettings::SETTING_REPLY_PERSONAL);
				if(!empty($group_personal))
					$reply_personal = $group_personal;
			}
			
			try {
				if(null == $mailer)
					$mailer = $mail_service->getMailer(CerberusMail::getMailerDefaults());
					
			 	// Create the message

				$mail = $mail_service->createMessage();
				$mail->setTo(array($notify_emails));
				$mail->setFrom(array($reply_to => $reply_personal));
				$mail->setReplyTo($reply_to);
				$mail->setSubject(sprintf("[RW: assignment #%s]: %s",
					$ticket->mask,
					$ticket->subject
				));
				
				$headers = $mail->getHeaders();
				
				if(false !== (@$in_reply_to = $last_headers['in-reply-to'])) {
					$headers->addTextHeader('References', $in_reply_to);
					$headers->addTextHeader('In-Reply-To', $in_reply_to);
				}
					
				$headers->addTextHeader('X-Mailer','Cerberus Helpdesk (Build '.APP_BUILD.')');
				$headers->addTextHeader('Precedence','List');
				$headers->addTextHeader('Auto-Submitted','auto-generated');
					
				$mail->setBody($last_message->getContent());					
				
				$result = $mailer->send($mail);
					
			} catch(Exception $e) {
				if(!empty($message_id)) {
					$fields = array(
						DAO_MessageNote::MESSAGE_ID => $message_id,
						DAO_MessageNote::CREATED => time(),
						DAO_MessageNote::WORKER_ID => 0,
						DAO_MessageNote::CONTENT => 'Exception thrown while sending watcher email: ' . $e->getMessage(),
						DAO_MessageNote::TYPE => Model_MessageNote::TYPE_ERROR,
					);
					DAO_MessageNote::create($fields);
				}
			}
		}
	}
        
	private function _sendForwards($event, $is_inbound) {
		@$ticket_id = $event->params['ticket_id'];
		@$send_worker_id = $event->params['worker_id'];
    	
		$url_writer = DevblocksPlatform::getUrlService();
		
		$ticket = DAO_Ticket::getTicket($ticket_id);

		// (Action) Forward Email To:
		
		// Sanitize and combine all the destination addresses
		$next_worker = DAO_Worker::getAgent($ticket->next_worker_id);
		$notify_emails = $next_worker->email;
			
		if(empty($notify_emails))
			return;
		
		// [TODO] This could be more efficient
		$messages = DAO_Ticket::getMessagesByTicket($ticket_id);
		$message = end($messages); // last message
		unset($messages);
		$headers = $message->getHeaders();
			
		// The whole flipping Swift section needs wrapped to catch exceptions
		try {
			$settings = DevblocksPlatform::getPluginSettingsService();
			$reply_to = $settings->get('cerberusweb.core',CerberusSettings::DEFAULT_REPLY_FROM,CerberusSettingsDefaults::DEFAULT_REPLY_FROM);
			
			// See if we need a group-specific reply-to
			if(!empty($ticket->team_id)) {
				@$group_from = DAO_GroupSettings::get($ticket->team_id, DAO_GroupSettings::SETTING_REPLY_FROM, '');
				if(!empty($group_from))
					$reply_to = $group_from;
			}
			
			$sender = DAO_Address::get($message->address_id);
	
			$sender_email = strtolower($sender->email);
			$sender_split = explode('@', $sender_email);
	
			if(!is_array($sender_split) || count($sender_split) != 2)
				return;
	
			// If return-path is blank
			if(isset($headers['return-path']) && $headers['return-path'] == '<>')
				return;
				
			// Ignore bounces
			if($sender_split[0]=="postmaster" || $sender_split[0] == "mailer-daemon")
				return;
			
			// Ignore autoresponses autoresponses
			if(isset($headers['auto-submitted']) && $headers['auto-submitted'] != 'no')
				return;
				
			// Attachments
			$attachments = $message->getAttachments();
			$mime_attachments = array();
			if(is_array($attachments))
			foreach($attachments as $attachment) {
				if(0 == strcasecmp($attachment->display_name,'original_message.html'))
					continue;
					
				$attachment_path = APP_STORAGE_PATH . '/attachments/'; // [TODO] This is highly redundant in the codebase
				if(!file_exists($attachment_path . $attachment->filepath))
					continue;
				
				$attach = Swift_Attachment::fromPath($attachment_path . $attachment->filepath);
				if(!empty($attachment->display_name))
					$attach->setFilename($attachment->display_name);
				$mime_attachments[] = $attach;
			}
	    	
			// Send copies
			$mail_service = DevblocksPlatform::getMailService();
			$mailer = $mail_service->getMailer(CerberusMail::getMailerDefaults());
				
			$mail = $mail_service->createMessage(); /* @var $mail Swift_Message */
			$mail->setTo(array($notify_emails));
			$mail->setFrom(array($sender->email));
			$mail->setReplyTo($reply_to);
			$mail->setReturnPath($reply_to);
			$mail->setSubject(sprintf("[RW: %s #%s]: %s",
				($is_inbound ? 'inbound' : 'outbound'),
				$ticket->mask,
				$ticket->subject
			));

			$hdrs = $mail->getHeaders();
			
			if(null !== (@$msgid = $headers['message-id'])) {
				$hdrs->addTextHeader('Message-Id',$msgid);
			}
				
			if(null !== (@$in_reply_to = $headers['in-reply-to'])) {
			    $hdrs->addTextHeader('References', $in_reply_to);
			    $hdrs->addTextHeader('In-Reply-To', $in_reply_to);
			}
			
			$hdrs->addTextHeader('X-Mailer','Cerberus Helpdesk (Build '.APP_BUILD.')');
			$hdrs->addTextHeader('Precedence','List');
			$hdrs->addTextHeader('Auto-Submitted','auto-generated');
			
			$mail->setBody($message->getContent());
	
			// Send message attachments with watcher
			if(is_array($mime_attachments))
			foreach($mime_attachments as $mime_attachment) {
				$mail->attach($mime_attachment);
			}
				
			$result = $mailer->send($mail);
		} catch(Exception $e) {
			if(!empty($message_id)) {
				$fields = array(
					DAO_MessageNote::MESSAGE_ID => $message_id,
					DAO_MessageNote::CREATED => time(),
					DAO_MessageNote::WORKER_ID => 0,
					DAO_MessageNote::CONTENT => 'Exception thrown while sending watcher email: ' . $e->getMessage(),
					DAO_MessageNote::TYPE => Model_MessageNote::TYPE_ERROR,
				);
				DAO_MessageNote::create($fields);
			}
		}
	}
};

