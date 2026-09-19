<?php

namespace GovTech\MyEmail\Exceptions;

/**
 * A failure that may succeed on a later attempt: the API was unreachable,
 * returned 5xx, or reported a quota that resets.
 */
class MyEmailTransientException extends MyEmailRequestException {}
