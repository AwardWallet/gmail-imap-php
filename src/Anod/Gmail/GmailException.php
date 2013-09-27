<?
namespace Anod\Gmail;


class GmailException extends \Exception {

	public $messageData;

	public function __construct($message, $code = 0, $previous = null, $messageData = []){
		parent::__construct($message, $code, $previous);
		$this->messageData = $messageData;
	}

}