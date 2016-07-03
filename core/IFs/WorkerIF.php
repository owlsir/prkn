<?php

namespace Prkn\Core\IFs;

interface WorkerIF {
    
    public function handle($redisKey);

}

