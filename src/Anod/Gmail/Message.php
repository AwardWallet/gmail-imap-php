<?php
namespace Anod\Gmail;
/**
 * Represents one message
 * 
 * @author Alex Gavrishev <alex.gavrishev@gmail.com>
 * 
 * @see https://developers.google.com/google-apps/gmail/imap_extensions
 * 
 */
class Message extends \Zend\Mail\Storage\Message {

    private $uid;
	
    public function __construct(array $params){
        parent::__construct($params);
        if(isset($params['UID']))
            $this->uid = $params['UID'];
    }

	/**
	 * Attached labels
	 * @return array <string>
	 */
	public function getLabels() {
		if ($this->getHeaders()->get('x-gm-labels'))  {
			return $this->getHeader('x-gm-labels', 'array');
		}
		return array();
	}
	
	/**
	 * Thread Id of the message
	 * @return string
	 */
	public function getThreadId() {
		return $this->getHeader('x-gm-thrid', 'string');
	}

	/**
	 * Message Id of the message
	 * @return string
	 */
	public function getMessageId() {
		return $this->getHeader('x-gm-msgid', 'string');
	}

	public function getUid(){
	    return $this->uid;
    }

}