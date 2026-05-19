<script setup>
import { computed } from 'vue';
import { Tag, Empty, Tooltip } from 'ant-design-vue';
import {
    PlusCircleFilled, EditFilled, DeleteFilled,
    UndoOutlined, ExportOutlined, EyeFilled, ClockCircleOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import 'dayjs/locale/es';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

dayjs.extend(relativeTime);
dayjs.locale('es');

const { t } = useI18n();
const { formatDateTimeFull } = useDateFormat();

/**
 * ActivityTimeline — feed cronológico de eventos de audit_log.
 *
 * Render visual tipo timeline (línea vertical con eventos en orden DESC).
 * Para cada evento muestra: ícono + actor + acción + cuándo + diff de campos
 * (si hay old/new values).
 *
 * Reusable: cualquier Show de cualquier módulo lo puede mostrar pasando los
 * audit_logs del registro como `activity`.
 */

const props = defineProps({
    activity: { type: Array, required: true },
});

const eventMeta = (event) => {
    switch (event) {
        case 'created':       return { icon: PlusCircleFilled, color: '#1D7044', label: t('global.event_created') };
        case 'updated':       return { icon: EditFilled,        color: '#0A6ED1', label: t('global.event_updated') };
        case 'deleted':       return { icon: DeleteFilled,      color: '#C8281D', label: t('global.event_deleted') };
        case 'force_deleted': return { icon: DeleteFilled,      color: '#7E1810', label: t('global.event_force_deleted') };
        case 'restored':      return { icon: UndoOutlined,      color: '#1D7044', label: t('global.event_restored') };
        case 'exported':      return { icon: ExportOutlined,    color: '#6A6D70', label: t('global.event_exported') };
        case 'export_queued': return { icon: ExportOutlined,    color: '#6A6D70', label: t('global.event_export_queued') };
        default:              return { icon: EyeFilled,         color: '#6A6D70', label: event };
    }
};

const fmtRelative = (iso) => iso ? dayjs(iso).fromNow() : '—';
// Tooltip absoluto del history: dd-mm-aaaa HH:mm:ss en TZ del user.
// Los segundos importan para auditoría (orden exacto de eventos cercanos).
//   "15-05-2026 18:03:41"
const fmtAbsolute = (iso) => formatDateTimeFull(iso);

/**
 * Calcula los campos que cambiaron entre old_values y new_values.
 * Returns array de { field, before, after }.
 */
const diffFields = (log) => {
    if (log.event !== 'updated') return [];
    const old = log.old_values ?? {};
    const next = log.new_values ?? {};
    const fields = new Set([...Object.keys(old), ...Object.keys(next)]);
    return [...fields]
        .filter(k => !['updated_at', 'created_at'].includes(k))
        .map(k => ({
            field: k,
            before: formatValue(old[k]),
            after:  formatValue(next[k]),
        }));
};

const formatValue = (v) => {
    if (v === null || v === undefined) return '—';
    if (v === true)  return 'Sí';
    if (v === false) return 'No';
    return String(v);
};
</script>

<template>
    <div v-if="activity.length === 0" class="activity-empty">
        <Empty :description="$t('global.no_activity')" />
    </div>
    <ul v-else class="activity-timeline">
        <li
            v-for="log in activity"
            :key="log.id"
            class="activity-item"
        >
            <div class="activity-item__icon" :style="{ background: eventMeta(log.event).color }">
                <component :is="eventMeta(log.event).icon" />
            </div>
            <div class="activity-item__body">
                <div class="activity-item__head">
                    <span class="activity-item__actor">
                        {{ log.user?.name ?? 'Sistema' }}
                    </span>
                    <span class="activity-item__action">
                        {{ eventMeta(log.event).label.toLowerCase() }}
                    </span>
                    <Tooltip :title="fmtAbsolute(log.created_at)">
                        <span class="activity-item__time">
                            <ClockCircleOutlined /> {{ fmtRelative(log.created_at) }}
                        </span>
                    </Tooltip>
                </div>

                <!-- Diff de campos (solo para 'updated') -->
                <div v-if="diffFields(log).length > 0" class="activity-diff">
                    <div
                        v-for="d in diffFields(log)"
                        :key="d.field"
                        class="activity-diff__row"
                    >
                        <span class="activity-diff__field">{{ d.field }}:</span>
                        <span class="activity-diff__before">{{ d.before }}</span>
                        <span class="activity-diff__arrow">→</span>
                        <span class="activity-diff__after">{{ d.after }}</span>
                    </div>
                </div>

                <!-- Para 'exported' mostrar formato si está disponible -->
                <div v-else-if="log.event === 'exported' && log.new_values?.format" class="activity-meta">
                    <Tag color="default" :bordered="false">
                        {{ log.new_values.format.toUpperCase() }}
                    </Tag>
                    <span v-if="log.new_values.scope" class="activity-meta__hint">
                        alcance: {{ log.new_values.scope }}
                    </span>
                </div>
            </div>
        </li>
    </ul>
</template>

<style scoped>
.activity-empty { padding: 24px 16px; }

.activity-timeline {
    list-style: none;
    margin: 0;
    padding: 0;
    position: relative;
}
.activity-timeline::before {
    content: '';
    position: absolute;
    left: 14px;
    top: 0;
    bottom: 0;
    width: 1px;
    background: #E5E5E5;
}
.activity-item {
    position: relative;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 8px 0;
}
.activity-item:not(:last-child) {
    padding-bottom: 16px;
}
.activity-item__icon {
    flex-shrink: 0;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #6A6D70;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    z-index: 1;
}
.activity-item__body {
    flex: 1;
    min-width: 0;
    padding-top: 4px;
}
.activity-item__head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 6px;
    font-size: 0.875rem;
    color: #32363A;
    line-height: 1.4;
}
.activity-item__actor {
    font-weight: 600;
    color: #1f2937;
}
.activity-item__action {
    color: #6A6D70;
}
.activity-item__time {
    margin-left: auto;
    font-size: 0.78rem;
    color: #6A6D70;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.activity-item__time :deep(.anticon) { font-size: 0.7rem; }

.activity-diff {
    margin-top: 8px;
    padding: 8px 12px;
    background: #F8FAFC;
    border-left: 2px solid #0A6ED1;
    border-radius: 0 4px 4px 0;
    font-size: 0.8125rem;
}
.activity-diff__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    color: #32363A;
    line-height: 1.5;
}
.activity-diff__row + .activity-diff__row {
    margin-top: 2px;
}
.activity-diff__field {
    font-weight: 600;
    color: #475569;
    text-transform: capitalize;
}
.activity-diff__before {
    background: rgba(200, 40, 29, 0.08);
    color: #C8281D;
    padding: 1px 6px;
    border-radius: 3px;
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 0.78rem;
    text-decoration: line-through;
}
.activity-diff__arrow {
    color: #6A6D70;
    font-weight: 600;
}
.activity-diff__after {
    background: rgba(29, 112, 68, 0.10);
    color: #1D7044;
    padding: 1px 6px;
    border-radius: 3px;
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-size: 0.78rem;
    font-weight: 600;
}

.activity-meta {
    margin-top: 6px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.78rem;
}
.activity-meta__hint { color: #6A6D70; }
</style>

<style>
/* Dark mode */
html[data-theme="dark"] .activity-timeline::before { background: #3f4448; }
html[data-theme="dark"] .activity-item__head    { color: #e5e6e7; }
html[data-theme="dark"] .activity-item__actor   { color: #e5e6e7; }
html[data-theme="dark"] .activity-item__action,
html[data-theme="dark"] .activity-item__time    { color: #a8aaae; }
html[data-theme="dark"] .activity-diff {
    background: #2c3034;
    border-left-color: #4db6e8;
}
html[data-theme="dark"] .activity-diff__row     { color: #e5e6e7; }
html[data-theme="dark"] .activity-diff__field   { color: #cbd5e1; }
</style>
