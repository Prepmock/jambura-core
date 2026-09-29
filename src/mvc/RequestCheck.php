<?php
namespace Jambura\Mvc;

/**
 * A rule about a request that is worth its own class.
 *
 * Pass one to RequestValidator::check() where several controllers share the
 * rule. For a rule used in one place, a closure or a controller method is
 * lighter:
 *
 *     ->check(new DuringOpeningHours())
 *     ->check([$this, 'withinQuota'])
 *     ->check(fn (Request $r) => $r->input('to') !== $r->input('from'))
 */
interface RequestCheck
{
    /**
     * Decides whether the request may go ahead.
     *
     * @param Request $request the request being answered
     * @return bool|string|array true to pass; false for a plain 422; a string
     *                           for a 422 with that message; or an array such as
     *                           ['status' => 429, 'error' => 'Quota used up'] to
     *                           choose the status, optionally with 'fields'
     */
    public function passes(Request $request);
}
