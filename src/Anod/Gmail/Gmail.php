<?php
namespace Anod\Gmail;
use Zend\Mail\Exception\InvalidArgumentException;
use Zend\Mail\Protocol\Exception\RuntimeException;

/**
 * 
 * TODO: moveToInbox, markAsRead/markAsUnread
 * 
 * @author Alex Gavrishev <alex.gavrishev@gmail.com>
 * 
 * @see https://developers.google.com/google-apps/gmail/imap_extensions
 * 
 */
class Gmail extends \Zend\Mail\Storage\Imap {
	const GMAIL_HOST = 'imap.gmail.com';
	const GMAIL_PORT = 993;
	const USE_SSL = 'ssl';

	const MAILBOX_INBOX	 	= 'INBOX';
	const MAILBOX_ALL	 	= '[Gmail]/All Mail';
	const MAILBOX_DRAFTS 	= '[Gmail]/Drafts';
	const MAILBOX_IMPORTANT = '[Gmail]/Important';
	const MAILBOX_SENT 		= '[Gmail]/Sent Mail';
	const MAILBOX_SPAM		= '[Gmail]/Spam';
	const MAILBOX_STARRED	= '[Gmail]/Starred';
	const MAILBOX_TRASH 	= '[Gmail]/Trash';
	
	/**
	 * @var \Anod\Gmail\OAuth
	 */
	private $oauth;
	/**
	 * @var bool
	 */
	private $debug = false;
	/**
	 *
	 * @param \Zend\Mail\Protocol\Imap $imap
	 */
	public function __construct(\Zend\Mail\Protocol\Imap $protocol) {
		$this->protocol = $protocol;
		$this->oauth = new OAuth($protocol);
		$this->messageClass = 'Anod\Gmail\Message';
	}
	
	/**
	 * 
	 * @param string $name
	 * @param string $version
	 * @param string $vendor
	 * @param string $contact
	 * @return \Anod\Gmail\Gmail
	 */
	public function setId($name, $version, $vendor, $contact) {
		$this->id =array(
			"name" , $name,
			"version" , $version,
			"vendor" , $vendor,
			"contact" , $contact	
		);
		return $this;
	}
	
	/**
	 * @param bool $debug
	 * @return \Anod\Gmail\Gmail
	 */
	public function setDebug($debug) {
		$this->debug = (bool)$debug;
		return $this;
	}
	/**
	 * @return mixed
	 */	
	public function sendId() {
		$escaped = array();
		foreach($this->id AS $value) {
			$escaped[] = $this->protocol->escapeString($value);
		}
		return $this->protocol->requestAndResponse('ID', array(
			$this->protocol->escapeList($escaped))
		);
	}
	
	/**
	 * 
	 * @param string $email
	 * @param string $accessToken
	 * @return \Anod\Gmail\Gmail
	 */
	public function authenticate($email, $accessToken) {
		$this->oauth->authenticate($email, $accessToken);
		return $this;
	}
	
	/**
	 * 
	 * @return \Zend\Mail\Protocol\Imap
	 */
	public function getProtocol() {
		return $this->protocol;
	}
	
	/**
	 *
	 * @return \Anod\Gmail\Gmail
	 */
	public function connect() {
		$this->protocol->connect(self::GMAIL_HOST, self::GMAIL_PORT, self::USE_SSL);
		return $this;
	}

	/**
	 * 
	 * @throws GmailException
	 * @return \Anod\Gmail\Gmail
	 */
	public function selectInbox() {
		$result = $this->protocol->select(self::MAILBOX_INBOX);
		if (!$result) {
			throw new GmailException("Cannot select ".self::MAILBOX_INBOX);
		}
		return $this;
	}
	
	/**
	 *
	 * @throws GmailException
	 * @return \Anod\Gmail\Gmail
	 */
	public function selectAllMail() {
		$result = $this->protocol->select(self::MAILBOX_ALL);
		if (!$result) {
			throw new GmailException("Cannot select ".self::MAILBOX_ALL);
		}
		return $this;
	}
	
	/**
	 * @return int
	 */
	public function getUID($msgid) {
		$search_response = $this->protocol->requestAndResponse('UID SEARCH', array('X-GM-MSGID', $msgid));
		if (isset($search_response[0][1])) {
			return (int)$search_response[0][1];
		}
		throw new GmailException("Cannot retrieve message uid. ".var_export($search_response, TRUE));
	}

	/**
	 * 
	 * @param int $uid
	 * @return bool
	 */
	public function archive($uid) {
		return $this->moveMessageUID($uid, self::MAILBOX_ALL);
	}
	
	/**
	 * 
	 * @param int $uid
	 * @return bool
	 */
	public function trash($uid) {
		return $this->moveMessageUID($uid, self::MAILBOX_TRASH);
	}
	
	/**
	 * 
	 * @param int $uid
	 * @param string $dest
	 * @return boolean
	 */
	public function moveMessageUID($uid, $dest)
	{
		$copy_response = $this->copyMessageUID($uid, $dest);
		if ($copy_response) {
			//Flag as deleted in the current box
			return $this->removeMessageUID($uid);
		}
		return false;
	}

    /**
   	 *
   	 * @param int $uid
   	 * @param string $dest
   	 */
   	public function moveMessagesByUid(array $ids, $dest)
   	{
        $result = $this->protocol->requestAndResponse('UID MOVE', array(implode(",", $ids), $this->protocol->escapeString($dest)));
        if(empty($result))
            throw new RuntimeException('cannot move messages: ' . json_encode($result));
   	}

	/**
	 * 
	 * @param int $uid
	 * @param string $dest
	 * @return mixed
	 */
	public function copyMessageUID($uid, $dest) {
		$folder = $this->protocol->escapeString($dest);
		return $this->protocol->requestAndResponse('UID COPY', array($uid, $folder), true);
	} 

	/**
	 * Apply flag to message by UID
	 * @param int $uid
	 * @param array $flags
	 * @return mixed
	 */
	public function setFlagsUID($uid,array $flags) {
		$itemList = $this->protocol->escapeList($flags);
		return $this->protocol->requestAndResponse('UID STORE', array($uid, '+FLAGS', $itemList), true);
	}
	
	/**
	 * Mark message as \Deleted by UID
	 * @param int $uid
	 * @return bool
	 */
	public function removeMessageUID($uid) {
		//Flag as deleted in the current box
		$flags = array(\Zend\Mail\Storage::FLAG_DELETED);
		$this->setFlagsUID($uid, $flags);
		return $this->protocol->expunge();
	}
	
	/**
	 * @param int $uid
	 * @param string $label
	 * @return null|bool|array tokens if success, false if error, null if bad request
	 */	
	public function applyLabel($uid, $label) {
		return $this->applyLabels($uid, array($label));
	}
	
	/**
	 * 
	 * @param int $uid
	 * @param array $labels <string>
	 * @return null|bool|array tokens if success, false if error, null if bad request
	 */
	public function applyLabels($uid, array $labels) {
		$this->storeLabels($uid, '+X-GM-LABELS', $labels);
	}

	/**
	 * 
	 * @param int $uid
	 * @param string $label
	 * @return null|bool|array tokens if success, false if error, null if bad request
	 */
	public function removeLabel($uid, $label) {
		return $this->removeLabels($uid, array($label));
	}
	
	/**
	 *
	 * @param int $uid
	 * @param array $labels <string>
	 * @return null|bool|array tokens if success, false if error, null if bad request
	 */
	public function removeLabels($uid, array $labels) {
		return $this->storeLabels($uid, '-X-GM-LABELS', $labels);
	}
	
	/**
	 * List all labels for message with $uid
	 * @param int $uid
	 * @throws GmailException
	 * @return array <string> labels
	 */
	public function getLabels($uid) {
		$itemList = $this->protocol->escapeList(array('X-GM-LABELS'));
		
		$fetch_response = $this->protocol->requestAndResponse('UID FETCH', array($uid, $itemList));
		if (!isset($fetch_response[0][2]) || !is_array($fetch_response[0][2]) || !isset($fetch_response[0][2][1])) {
			throw new GmailException("Cannot retrieve list of labels by uid. ".var_export($fetch_response, TRUE));
		}
		return $fetch_response[0][2][1];
	}
	
	/**
	 * 
	 * @param int $uid
	 * @throws GmailException
	 * @return array
	 */
	public function getMessageDataRaw($uid) {
		$items = array('FLAGS', 'RFC822.HEADER', 'RFC822.TEXT', 'X-GM-LABELS', 'X-GM-THRID');
		$itemList = $this->protocol->escapeList($items);
		
		$fetch_response = $this->protocol->requestAndResponse('UID FETCH', array($uid, $itemList));
		if (!isset($fetch_response[0][2]) || !is_array($fetch_response[0][2])) {
			throw new GmailException("Cannot retrieve message by uid. ".var_export($fetch_response, TRUE));
		}
		$response_count = count($fetch_response);
		$data = array();
		for($i = 0; $i < $response_count; $i++) {
			$tokens = $fetch_response[$i];
			// ignore other responses
			if ($tokens[1] != 'FETCH') {
				continue;
			}
			
			while (key($tokens[2]) !== null) {
				$data[current($tokens[2])] = next($tokens[2]);
				next($tokens[2]);
			}
		}
		return $data;
	}
	
	/**
	 * 
	 * @param int $uid
	 * @throws GmailException
	 * @return string
	 */
	public function getThreadId($uid) {
		$fetch_response = $this->protocol->requestAndResponse('UID FETCH', array($uid, 'X-GM-THRID'));
		if (!isset($fetch_response[0][2]) || !is_array($fetch_response[0][2]) || !isset($fetch_response[0][2][1])) {
			throw new GmailException("Cannot retrieve thread id by uid. ".var_export($fetch_response, TRUE));
		}
		return $fetch_response[0][2][1];
	}
	
	/**
	 * 
	 * @param int $uid
	 * @return \Zend\Mail\Storage\Message
	 */
	public function getMessageData($uid) {
		$data = $this->getMessageDataRaw($uid);
		
		$header = $data['RFC822.HEADER'];		
		$content = $data['RFC822.TEXT'];
		$threadId = $data['X-GM-THRID'];
		$labels = $data['X-GM-LABELS'];
		$flags = array();
		foreach ($data['FLAGS'] as $flag) {
			$flags[] = isset(static::$knownFlags[$flag]) ? static::$knownFlags[$flag] : $flag;
		}
		
		$msg = new Message(array(
			'handler' => $this,
			'id' => $uid,
			'headers' => $header,
			'content' => $content,
			'flags' => $flags
		));
		$msgHeaders = $msg->getHeaders();
		$msgHeaders->addHeaderLine('x-gm-thrid', $threadId);
		if ($labels) {
			foreach($labels AS $label) {
				$msgHeaders->addHeaderLine('x-gm-labels', $label);
			}
		}
		return $msg;
	}
	
	/**
	 *
	 * @param int $uid
	 * @param string $command
	 * @param array $labels
	 * @return null|bool|array tokens if success, false if error, null if bad request
	 */
	protected function storeLabels($uid, $command, array $labels) {
		$escapedLabels = array();
		foreach($labels AS $label) {
			$escapedLabels[] = $this->protocol->escapeString($label);
		}
	
		$labelsList = $this->protocol->escapeList($escapedLabels);
		$response = $this->protocol->requestAndResponse('UID STORE', array($uid, $command, $labelsList));
		return $response;
	}
	
	/**
	 * Fetch a message
	 *
	 * @param int|int[] $id number of message
	 * @return \Zend\Mail\Storage\Message|\Zend\Mail\Storage\Message[]
	 * @throws \Zend\Mail\Protocol\Exception\RuntimeException
	 */
	public function getMessage($id)
	{
		$responses = $this->protocol->fetch(array('FLAGS', 'RFC822.HEADER', 'RFC822.TEXT', 'X-GM-LABELS', 'X-GM-THRID', 'X-GM-MSGID', 'UID'), $id);
        if(isset($responses['FLAGS']))
            $responses = [$responses];

        $result = [];
        foreach ($responses as $data) {
            $labels = $data['X-GM-LABELS'];

            $flags = array();
            foreach ($data['FLAGS'] as $flag) {
                $flags[] = isset(static::$knownFlags[$flag]) ? static::$knownFlags[$flag] : $flag;
            }

            /** @var Message $msg */
            try {
                $msg = new $this->messageClass(array('handler' => $this, 'id' => $id, 'headers' => $data['RFC822.HEADER'], 'flags' => $flags, 'content' => $data['RFC822.TEXT'], 'UID' => $data['UID']));
            } catch (\Zend\Mail\Exception\RuntimeException $e) {
                throw new GmailException($e->getMessage(), 0, $e, $data);
            } catch (\Zend\Mail\Header\Exception\InvalidArgumentException $e) {
                throw new GmailException($e->getMessage(), 0, $e, $data);
            } catch (InvalidArgumentException $e) {
				throw new GmailException($e->getMessage(), 0, $e, $data);
			}
            $msgHeaders = $msg->getHeaders();
            $msgHeaders->addHeaderLine('x-gm-thrid', $data['X-GM-THRID']);
            $msgHeaders->addHeaderLine('x-gm-msgid', $data['X-GM-MSGID']);
            $msgHeaders->addHeaderLine('x-uid', $data['UID']);
            if ($labels) {
                foreach ($labels AS $label) {
                    $msgHeaders->addHeaderLine('x-gm-labels', $label);
                }
            }
            $result[] = $msg;
        }

        if(is_array($id))
            return $result;
        else
            return $result[0];
	}

	public function listFolders($rootFolder = null){
		return $this->protocol->listMailbox((string) $rootFolder);
	}

}
