<?php

function unsupported_coalesce()
{
    return (new stdClass())->missing ?? 'fallback';
}
