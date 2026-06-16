<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashbookLabel;
use App\Models\CashTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CashbookLabelController extends Controller
{
    private const OLD_REPAIR_MECHANIC_LABELS = ['+', '1', '2'];

    public function index(): View
    {
        $this->mergeRepairMechanicLabels();

        return view('admin.cashbook_labels.index', [
            'labels' => CashbookLabel::query()
                ->with('parent')
                ->whereNotIn('name', self::OLD_REPAIR_MECHANIC_LABELS)
                ->orderBy('name')
                ->get(),
            'parentOptions' => CashbookLabel::query()
                ->whereNotIn('name', self::OLD_REPAIR_MECHANIC_LABELS)
                ->orderBy('name')
                ->get(['id', 'name']),
            'usage' => CashTransaction::query()
                ->select('label')
                ->selectRaw('COUNT(*) as total')
                ->whereNotNull('label')
                ->where('label', '<>', '')
                ->groupBy('label')
                ->pluck('total', 'label'),
            'operationTypes' => $this->operationTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        CashbookLabel::query()->create($this->payload($request));

        return redirect()->route('admin.cashbook-labels.index')->with('status', 'Метка добавлена.');
    }

    public function update(Request $request, CashbookLabel $cashbookLabel): RedirectResponse
    {
        $payload = $this->payload($request, $cashbookLabel);
        $oldName = $cashbookLabel->name;

        DB::transaction(function () use ($cashbookLabel, $payload, $oldName): void {
            $cashbookLabel->update($payload);

            if ($oldName !== $cashbookLabel->name) {
                CashTransaction::query()
                    ->where('label', $oldName)
                    ->update(['label' => $cashbookLabel->name]);
            }
        });

        return redirect()->route('admin.cashbook-labels.index')->with('status', 'Метка обновлена.');
    }

    public function destroy(CashbookLabel $cashbookLabel): RedirectResponse
    {
        CashbookLabel::query()
            ->where('parent_id', $cashbookLabel->id)
            ->update(['parent_id' => null]);

        $cashbookLabel->delete();

        return redirect()->route('admin.cashbook-labels.index')->with('status', 'Метка удалена из списка выбора.');
    }

    protected function payload(Request $request, ?CashbookLabel $cashbookLabel = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
        ]);

        if (in_array($request->input('name'), self::OLD_REPAIR_MECHANIC_LABELS, true)) {
            $request->merge(['name' => '']);
        }

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('cashbook_labels', 'name')->ignore($cashbookLabel),
            ],
            'operation_type' => ['required', Rule::in(array_keys($this->operationTypes()))],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('cashbook_labels', 'id'),
                Rule::notIn(array_filter([$cashbookLabel?->id])),
            ],
        ]);
    }

    protected function operationTypes(): array
    {
        return [
            'income' => 'Приход',
            'expense' => 'Расход',
            'exchange' => 'Обмен',
        ];
    }

    protected function mergeRepairMechanicLabels(): void
    {
        if (! CashbookLabel::query()->whereIn('name', self::OLD_REPAIR_MECHANIC_LABELS)->exists()) {
            return;
        }

        DB::transaction(function (): void {
            $operationType = CashbookLabel::query()
                ->whereIn('name', self::OLD_REPAIR_MECHANIC_LABELS)
                ->value('operation_type') ?? 'income';

            CashbookLabel::query()->firstOrCreate(
                ['name' => ''],
                ['operation_type' => $operationType],
            );

            CashTransaction::query()
                ->whereIn('label', self::OLD_REPAIR_MECHANIC_LABELS)
                ->update(['label' => '']);

            CashbookLabel::query()
                ->whereIn('name', self::OLD_REPAIR_MECHANIC_LABELS)
                ->delete();
        });
    }
}
