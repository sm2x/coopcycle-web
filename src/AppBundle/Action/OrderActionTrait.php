<?php

namespace AppBundle\Action;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Snc\RedisBundle\Client\Phpredis\Client as Redis;
use Symfony\Component\Serializer\SerializerInterface;
use AppBundle\Entity\OrderRepository;

trait OrderActionTrait
{
    protected $tokenStorage;
    protected $redis;
    protected $orderRepository;
    protected $serializer;

    public function __construct(TokenStorageInterface $tokenStorage, Redis $redis,
        OrderRepository $orderRepository, SerializerInterface $serializer)
    {
        $this->tokenStorage = $tokenStorage;
        $this->redis = $redis;
        $this->orderRepository = $orderRepository;
        $this->serializer = $serializer;
    }

    protected function getUser()
    {
        if (null === $token = $this->tokenStorage->getToken()) {
            return;
        }

        if (!is_object($user = $token->getUser())) {
            // e.g. anonymous authentication
            return;
        }

        return $user;
    }
}