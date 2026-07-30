import React, { useEffect, useState } from 'react';
import { Calendar, dateFnsLocalizer } from 'react-big-calendar';
import format from 'date-fns/format';
import parse from 'date-fns/parse';
import startOfWeek from 'date-fns/startOfWeek';
import getDay from 'date-fns/getDay';
import enUS from 'date-fns/locale/en-US';
import 'react-big-calendar/lib/css/react-big-calendar.css';
import { apiClient } from '../../../shared/api/axios';

const locales = {
  'en-US': enUS,
};

const localizer = dateFnsLocalizer({
  format,
  parse,
  startOfWeek,
  getDay,
  locales,
});

export const DeadlineCalendar = () => {
    const [events, setEvents] = useState<any[]>([]);
    
    useEffect(() => {
        const fetchDeadlines = async () => {
            try {
                const response = await apiClient.get('/api/deadlines');
                const rawData = response.data.data;
                const formattedEvents = rawData.map((d: any) => ({
                    id: d.id,
                    title: d.title,
                    start: new Date(d.start),
                    end: new Date(d.end),
                    allDay: true,
                    is_conflict: d.is_conflict
                }));
                setEvents(formattedEvents);
            } catch (err) {
                console.error("Failed to fetch deadlines", err);
            }
        };
        fetchDeadlines();
    }, []);

    const eventStyleGetter = (event: any, start: any, end: any, isSelected: boolean) => {
        let style = {
            backgroundColor: '#3174ad',
            borderRadius: '5px',
            opacity: 0.8,
            color: 'white',
            border: '0px',
            display: 'block'
        };

        if (event.is_conflict) {
            style.backgroundColor = '#ef4444'; // Red for conflict
        }

        return {
            style: style
        };
    };

    return (
        <div className="h-screen p-8 bg-slate-50">
            <h1 className="text-3xl font-bold text-slate-900 mb-6">Firm Deadlines Calendar</h1>
            <div className="bg-white p-6 rounded-xl shadow h-full max-h-[800px]">
                <Calendar
                    localizer={localizer}
                    events={events}
                    startAccessor="start"
                    endAccessor="end"
                    style={{ height: '100%' }}
                    eventPropGetter={eventStyleGetter}
                />
            </div>
        </div>
    );
};
