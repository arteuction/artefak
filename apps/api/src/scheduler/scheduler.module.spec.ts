/**
 * SchedulerModule — cron ownership switch
 *
 * SM1 SCHEDULER_ENABLED unset  — registers ScheduleModule (crons run)
 * SM2 SCHEDULER_ENABLED=true   — registers ScheduleModule
 * SM3 SCHEDULER_ENABLED=false  — registers nothing (crons do not run)
 */

import { schedulerImports } from './scheduler.module';

describe('SchedulerModule — SCHEDULER_ENABLED', () => {
  it('SM1: unset — registers ScheduleModule', () => {
    expect(schedulerImports({})).toHaveLength(1);
  });

  it('SM2: true — registers ScheduleModule', () => {
    expect(schedulerImports({ SCHEDULER_ENABLED: 'true' })).toHaveLength(1);
  });

  it('SM3: false — registers nothing', () => {
    expect(schedulerImports({ SCHEDULER_ENABLED: 'false' })).toHaveLength(0);
  });
});
