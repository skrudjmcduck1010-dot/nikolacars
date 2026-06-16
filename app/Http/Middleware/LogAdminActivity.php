<?php

namespace App\Http\Middleware;

use App\Models\AdminActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class LogAdminActivity
{
    /**
     * @param  Closure(Request): SymfonyResponse  $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $response = $next($request);

        if ($this->shouldLog($request, $response)) {
            $this->writeLog($request, $response);
        }

        return $response;
    }

    protected function shouldLog(Request $request, SymfonyResponse $response): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }

        if (! $request->routeIs('admin.*')) {
            return false;
        }

        if ($request->routeIs('admin.activity-logs.*')) {
            return false;
        }

        return $response->getStatusCode() < Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    protected function writeLog(Request $request, SymfonyResponse $response): void
    {
        try {
            AdminActivityLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => $this->actionLabel((string) $request->route()?->getName()),
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'status_code' => $response->getStatusCode(),
                'payload' => $this->sanitizedPayload($request),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function sanitizedPayload(Request $request): array
    {
        $payload = $request->except([
            '_token',
            '_method',
            'password',
            'password_confirmation',
            'current_password',
        ]);

        foreach ($payload as $key => $value) {
            if ($value instanceof UploadedFile) {
                $payload[$key] = $value->getClientOriginalName();
            }

            if (is_array($value)) {
                $payload[$key] = $this->sanitizeArray($value);
            }
        }

        return $payload;
    }

    protected function sanitizeArray(array $items): array
    {
        foreach ($items as $key => $value) {
            if (in_array((string) $key, ['password', 'password_confirmation', 'current_password'], true)) {
                unset($items[$key]);

                continue;
            }

            if ($value instanceof UploadedFile) {
                $items[$key] = $value->getClientOriginalName();
            } elseif (is_array($value)) {
                $items[$key] = $this->sanitizeArray($value);
            }
        }

        return $items;
    }

    protected function actionLabel(string $routeName): string
    {
        $parts = explode('.', $routeName);
        $resource = $parts[1] ?? 'admin';
        $action = $parts[2] ?? 'action';

        $resources = [
            'warehouses' => 'Склады',
            'locations' => 'Ячейки',
            'categories' => 'Категории',
            'brands' => 'Бренды',
            'donor-cars' => 'Доноры',
            'counterparties' => 'Контрагенты',
            'products' => 'Товары',
            'stock-items' => 'Остатки',
            'reservations' => '',
            'cashbook-labels' => 'Метки кассы',
            'cashbook' => 'Касса и работы',
            'valera-cashbook' => 'Касса Валера',
            'sto-work-orders' => 'Заказ-наряды',
            'sto-employees' => 'Сотрудники',
            'actions' => 'Складские операции',
        ];

        $actions = [
            'index' => 'Просмотр списка',
            'show' => 'Просмотр',
            'create' => 'Открытие формы создания',
            'edit' => 'Открытие формы изменения',
            'store' => 'Создание',
            'update' => 'Изменение',
            'destroy' => 'Удаление',
        ];

        return ($actions[$action] ?? 'Действие').' · '.($resources[$resource] ?? $resource);
    }
}
