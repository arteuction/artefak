// No-op stub for @nestjs/throttler — CJS module that breaks Jest ESM chain
import { Module, Injectable } from '@nestjs/common';

export const ThrottlerModule = {
  forRoot: () => ({ module: class ThrottlerModuleStub {}, providers: [], exports: [] }),
};

export const Throttle = () => () => {};
export const SkipThrottle = () => () => {};
export const ThrottlerGuard = class {};
