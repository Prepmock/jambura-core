<?php
class jamex extends Exception {};
class jamexPageNotFound extends jamex {};
class jamexBadController extends jamexPageNotFound {};
class jamexBadAction extends jamexPageNotFound {};
class jamexBadMethod extends jamexPageNotFound {};