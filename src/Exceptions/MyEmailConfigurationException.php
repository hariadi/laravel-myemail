<?php

namespace Hariadi\MyEmail\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The transport was asked to send without usable configuration, or was handed
 * a message it cannot represent.
 */
class MyEmailConfigurationException extends TransportException {}
