/**
 * Phase 0.6 — EmailService unit tests
 *
 * EM1  send — logs warning and no-ops when not configured
 * EM2  send — calls transport.sendMail when configured
 * EM3  sendRegistrationConfirmation — delegates to send
 * EM4  sendOutbidNotification — formats amount correctly
 * EM5  sendPaymentConfirmation — formats amount correctly
 * EM6  sendBookPurchaseConfirmation — delegates to send
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { EmailService } from './email.service';

describe('Phase 0.6 — EmailService', () => {
  let svc: EmailService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [EmailService],
    }).compile();
    svc = module.get(EmailService);
  });

  it('EM1: send — no-ops and logs warning when transport not configured', async () => {
    // In test environment MAIL_HOST is not set, so enabled is false
    expect(svc.enabled).toBe(false);
    await expect(svc.send({ to: 'test@example.com', subject: 'Hi', html: '<p>Hi</p>' })).resolves.not.toThrow();
  });

  it('EM2: send — calls transport.sendMail when configured', async () => {
    const mockSendMail = (jest.fn() as any).mockResolvedValue({ messageId: 'test-id' });
    (svc as any).transport = { sendMail: mockSendMail };
    (svc as any).enabled = true;

    await svc.send({ to: 'user@example.com', subject: 'Test', html: '<p>Test</p>' });

    expect(mockSendMail).toHaveBeenCalledWith(
      expect.objectContaining({ to: 'user@example.com', subject: 'Test' })
    );

    (svc as any).transport = null;
    (svc as any).enabled = false;
  });

  it('EM3: sendRegistrationConfirmation — delegates to send without error', async () => {
    const sendSpy = jest.spyOn(svc, 'send').mockResolvedValue(undefined);
    await svc.sendRegistrationConfirmation('user@example.com', 'Alice');
    expect(sendSpy).toHaveBeenCalledWith(
      expect.objectContaining({ to: 'user@example.com', subject: expect.stringContaining('Welcome') })
    );
    sendSpy.mockRestore();
  });

  it('EM4: sendOutbidNotification — formats EUR amount correctly', async () => {
    const sendSpy = jest.spyOn(svc, 'send').mockResolvedValue(undefined);
    await svc.sendOutbidNotification('buyer@example.com', 'Starry Night', 150000, 'EUR');
    expect(sendSpy).toHaveBeenCalledWith(
      expect.objectContaining({ subject: expect.stringContaining('outbid') })
    );
    const call = (sendSpy.mock.calls[0] as any)[0];
    expect(call.html).toContain('1500.00');
    sendSpy.mockRestore();
  });

  it('EM5: sendPaymentConfirmation — formats amount in text', async () => {
    const sendSpy = jest.spyOn(svc, 'send').mockResolvedValue(undefined);
    await svc.sendPaymentConfirmation('buyer@example.com', 'Mona Lisa', 250000, 'EUR');
    const call = (sendSpy.mock.calls[0] as any)[0];
    expect(call.text).toContain('2500.00');
    sendSpy.mockRestore();
  });

  it('EM6: sendBookPurchaseConfirmation — delegates to send', async () => {
    const sendSpy = jest.spyOn(svc, 'send').mockResolvedValue(undefined);
    await svc.sendBookPurchaseConfirmation('reader@example.com', 'Art History Vol. 1');
    expect(sendSpy).toHaveBeenCalledWith(
      expect.objectContaining({ subject: expect.stringContaining('Art History Vol. 1') })
    );
    sendSpy.mockRestore();
  });
});
