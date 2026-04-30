<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BatchNotificationRequest;
use App\Http\Requests\ListNotificationsRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationCollection;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    public function __construct(public NotificationService $notificationService) {}

    public function store(StoreNotificationRequest $request): JsonResponse
    {
        $notification = $this->notificationService->create($request->validated());

        return (new NotificationResource($notification))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function storeBatch(BatchNotificationRequest $request): JsonResponse
    {
        $result = $this->notificationService->createBatch($request->validated()['notifications']);

        return response()->json([
            'batch_id' => $result['batch_id'],
            'notifications' => new NotificationCollection($result['notifications']),
        ], Response::HTTP_CREATED);
    }

    public function show(Notification $notification): NotificationResource
    {
        $notification->load('logs');

        return new NotificationResource($notification);
    }

    public function showBatch(string $batchId): NotificationCollection
    {
        $notifications = Notification::query()
            ->byBatchId($batchId)
            ->orderByDesc('created_at')
            ->paginate(15);

        return new NotificationCollection($notifications);
    }

    public function cancel(Notification $notification): NotificationResource
    {
        return new NotificationResource(
            $this->notificationService->cancel($notification),
        );
    }

    public function index(ListNotificationsRequest $request): NotificationCollection
    {
        $paginator = $this->notificationService->list(
            $request->validated(),
            (int) $request->validated('per_page', 15),
        );

        return new NotificationCollection($paginator);
    }
}
