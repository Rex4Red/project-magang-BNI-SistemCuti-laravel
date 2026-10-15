import {describe,it,expect} from 'vitest';
import {formatDigitsToDate,formatIsoToDisplay,parseAnyDateInput} from '../src/App';
import {validDate} from '../shared/domain';

describe('Datepicker input formatting and validation', () => {
  it('hanya menerima angka dan memformat menjadi dd/mm/yyyy', () => {
    // Single digit
    expect(formatDigitsToDate('1')).toBe('1');
    // Two digits (day)
    expect(formatDigitsToDate('15')).toBe('15');
    // Three digits (day + partial month)
    expect(formatDigitsToDate('150')).toBe('15/0');
    // Four digits (day + full month)
    expect(formatDigitsToDate('1508')).toBe('15/08');
    // Five digits (day + month + partial year)
    expect(formatDigitsToDate('15082')).toBe('15/08/2');
    // Six digits
    expect(formatDigitsToDate('150820')).toBe('15/08/20');
    // Seven digits
    expect(formatDigitsToDate('1508202')).toBe('15/08/202');
    // Eight digits (complete date)
    expect(formatDigitsToDate('15082026')).toBe('15/08/2026');
  });

  it('mengabaikan dan membuang huruf serta karakter spesial', () => {
    expect(formatDigitsToDate('abc')).toBe('');
    expect(formatDigitsToDate('!@#$%^&*()_+')).toBe('');
    expect(formatDigitsToDate('15-08-2026')).toBe('15/08/2026');
    expect(formatDigitsToDate('15.08.2026')).toBe('15/08/2026');
    expect(formatDigitsToDate('15/08/2026')).toBe('15/08/2026');
    expect(formatDigitsToDate('a1b5c0d8e2f0g2h6')).toBe('15/08/2026');
  });

  it('membatasi maksimal 8 digit angka (dd/mm/yyyy)', () => {
    expect(formatDigitsToDate('1508202699999')).toBe('15/08/2026');
  });

  it('memvalidasi tanggal benar dan menolak tanggal tidak valid', () => {
    // Valid dates
    expect(validDate('2026-08-15')).toBe(true);
    expect(validDate('2024-02-29')).toBe(true); // Leap year 2024
    expect(validDate('2026-12-31')).toBe(true);

    // Invalid dates
    expect(validDate('2025-02-29')).toBe(false); // Non-leap year 2025
    expect(validDate('2026-04-31')).toBe(false); // April only 30 days
    expect(validDate('2026-02-30')).toBe(false);
    expect(validDate('2026-13-01')).toBe(false); // Month 13
  });

  it('mengonversi ISO YYYY-MM-DD ke tampilan dd/mm/yyyy', () => {
    expect(formatIsoToDisplay('2026-10-15')).toBe('15/10/2026');
    expect(formatIsoToDisplay('2026-12-28')).toBe('28/12/2026');
    expect(formatIsoToDisplay('')).toBe('');
  });

  it('mendukung parsing ISO YYYY-MM-DD dari automated tests / Playwright .fill()', () => {
    const res = parseAnyDateInput('2026-11-16');
    expect(res.formatted).toBe('16/11/2026');
    expect(res.iso).toBe('2026-11-16');

    const res2 = parseAnyDateInput('16/11/2026');
    expect(res2.formatted).toBe('16/11/2026');
    expect(res2.iso).toBe('2026-11-16');
  });
});
