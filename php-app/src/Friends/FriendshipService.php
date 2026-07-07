<?php

declare(strict_types=1);

namespace MoodSwings\Friends;

use MoodSwings\Repository\FriendshipRepository;
use MoodSwings\Repository\UserRepository;

final class FriendshipService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
    ) {
    }

    /**
     * @return array the invited user's record
     */
    public function sendInvite(int $requesterId, string $usernameOrEmail): array
    {
        $usernameOrEmail = trim($usernameOrEmail);
        $target = $this->users->findByUsername($usernameOrEmail) ?? $this->users->findByEmail($usernameOrEmail);

        if ($target === null) {
            throw new UserNotFoundException('No user found with that username or email.');
        }

        $targetId = (int) $target['id'];

        if ($targetId === $requesterId) {
            throw new CannotFriendSelfException('You cannot send a friend request to yourself.');
        }

        $existing = $this->friendships->findByPair($requesterId, $targetId);

        if ($existing !== null) {
            // Deliberately generic for 'blocked': the requester isn't told
            // they've been blocked specifically, just that it didn't work.
            throw new FriendshipAlreadyExistsException(match ($existing['status']) {
                'accepted' => 'You are already friends.',
                'pending' => 'A friend request already exists between you two.',
                default => 'Unable to send a friend request to this user.',
            });
        }

        [$low, $high] = $requesterId < $targetId ? [$requesterId, $targetId] : [$targetId, $requesterId];
        $this->friendships->create($low, $high, 'pending', $requesterId);

        return $target;
    }

    public function respondToInvite(int $userId, int $otherUserId, string $action): void
    {
        $friendship = $this->friendships->findByPair($userId, $otherUserId);

        if ($friendship === null || $friendship['status'] !== 'pending') {
            throw new FriendshipNotFoundException('No pending friend request from that user.');
        }

        if ((int) $friendship['action_user_id'] === $userId) {
            throw new NotAuthorizedToRespondException('You cannot respond to your own friend request.');
        }

        match ($action) {
            'accept' => $this->friendships->updateStatus((int) $friendship['id'], 'accepted', $userId),
            'decline' => $this->friendships->delete((int) $friendship['id']),
            'block' => $this->friendships->updateStatus((int) $friendship['id'], 'blocked', $userId),
            default => throw new \InvalidArgumentException('Action must be one of: accept, decline, block.'),
        };
    }

    public function listFriends(int $userId): array
    {
        return $this->friendships->listAcceptedForUser($userId);
    }

    public function listIncomingInvites(int $userId): array
    {
        return $this->friendships->listIncomingPendingForUser($userId);
    }

    public function listOutgoingInvites(int $userId): array
    {
        return $this->friendships->listOutgoingPendingForUser($userId);
    }
}
