<?php
namespace App\SSH;

/**
 * Something wrong with an SSH key or signature. The message is written to be
 * shown to the person signing in.
 */
class SSHException extends \Exception {}
