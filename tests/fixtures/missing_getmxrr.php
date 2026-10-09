<?php

namespace SMTPValidateEmail;

// Shadows the global function for unqualified calls inside the library's
// namespace, simulating a PHP build without getmxrr() (e.g. Windows).
function function_exists(string $function): bool
{
    return $function !== 'getmxrr' && \function_exists($function);
}
